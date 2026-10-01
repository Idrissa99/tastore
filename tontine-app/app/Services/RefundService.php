<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * PRIORITÉ 2 — Remboursements.
 *
 * Invariants :
 *  - un seul remboursement logique par contribution (index unique SQL) ;
 *  - seule une cotisation encaissée ("completed") est remboursable ;
 *  - une contribution déjà remboursée (pending ou processed) ne peut pas l'être
 *    à nouveau ;
 *  - le traitement est idempotent : un remboursement déjà traité ne peut pas
 *    l'être deux fois ;
 *  - aucun remboursement opérateur n'est simulé. Le mode "manual" signifie que
 *    l'admin a rendu l'argent hors plateforme et le déclare.
 */
class RefundService
{
    public function __construct(
        protected TransactionRunner $transactions,
    ) {}

    /**
     * @return array<int, string>
     */
    public function blockers(Contribution $contribution): array
    {
        $blockers = [];

        if (! $contribution->isPaid()) {
            $blockers[] = 'contribution_not_paid';
        }

        if ($contribution->refunds()->exists()) {
            $blockers[] = 'already_refunded';
        }

        return $blockers;
    }

    public function isRefundable(Contribution $contribution): bool
    {
        return $this->blockers($contribution) === [];
    }

    public function assertRefundable(Contribution $contribution): void
    {
        $blockers = $this->blockers($contribution);

        if ($blockers !== []) {
            abort(409, $this->messageFor($blockers));
        }
    }

    /**
     * Crée (ou retrouve) le remboursement d'une cotisation. Idempotent.
     * Ne déclenche jamais de transfert : la ligne reste "pending" jusqu'à ce
     * qu'un admin déclare la restitution manuelle effectuée.
     */
    public function createFor(Contribution $contribution, ?Tontine $tontine = null): Refund
    {
        return $this->transactions->run(function () use ($contribution, $tontine) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            $existing = $locked->refunds()->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            if (! $locked->isPaid()) {
                throw new \RuntimeException(
                    'Une cotisation non payée ne peut pas être remboursée : contribution '.$locked->id
                );
            }

            // PRIORITÉ 4 §5 : le remboursement ne peut pas dépasser le montant
            // encaissé. Une contrainte SQL ne peut pas exprimer une règle sur
            // DEUX tables : c'est le service qui porte cette garantie, et il
            // s'appuie sur le verrou de ligne (donc sur le montant validé).
            $amount = $locked->amount;
            $outstanding = (float) $amount - (float) $locked->refunds()->sum('amount');

            if ($outstanding <= 0) {
                throw new \RuntimeException(
                    'Cette cotisation est déjà intégralement remboursée : contribution '.$locked->id
                );
            }

            $locked->loadMissing('tontineMember');

            return Refund::create([
                'contribution_id' => $locked->id,
                'tontine_id' => $tontine?->id ?? $locked->tontineMember->tontine_id,
                'user_id' => $locked->tontineMember->user_id,
                'amount' => $outstanding,
                'method' => Refund::METHOD_MANUAL,
                'status' => Refund::STATUS_PENDING,
            ]);
        });
    }

    /**
     * Enregistre toutes les obligations de remboursement d'une tontine annulée.
     * Idempotent : relancer la méthode ne duplique aucune ligne.
     *
     * @return Collection<int, Refund>
     */
    public function createForTontine(Tontine $tontine): Collection
    {
        $contributions = Contribution::where('status', Contribution::STATUS_COMPLETED)
            ->whereHas('tontineMember', fn ($query) => $query->where('tontine_id', $tontine->id))
            ->orderBy('id')
            ->get();

        return $contributions
            ->map(fn (Contribution $contribution) => $this->createFor($contribution, $tontine))
            ->values();
    }

    public function assertProcessable(Refund $refund): void
    {
        abort_unless(
            $refund->isPending(),
            409,
            'Ce remboursement a déjà été traité.'
        );
    }

    /**
     * Marque le remboursement comme traité. Idempotent au sens "une seule fois" :
     * un remboursement déjà traité est refusé (409), jamais re-daté.
     */
    public function process(Refund $refund, User $admin, ?string $paymentReference = null): Refund
    {
        return $this->transactions->run(function () use ($refund, $admin, $paymentReference) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            $this->assertProcessable($locked);

            $updates = [
                'status' => Refund::STATUS_PROCESSED,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'method' => Refund::METHOD_MANUAL,
            ];

            if ($paymentReference !== null && $paymentReference !== '') {
                $updates['payment_reference'] = $paymentReference;
            }

            $locked->update($updates);

            return $locked->fresh();
        });
    }

    private function messageFor(array $blockers): string
    {
        if (in_array('contribution_not_paid', $blockers, true)) {
            return 'Seule une cotisation encaissée peut être remboursée.';
        }

        return 'Cette cotisation a déjà fait l\'objet d\'un remboursement.';
    }
}
