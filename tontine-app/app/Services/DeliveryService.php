<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Notifications\DeliveryConfirmedNotification;
use Illuminate\Support\Collection;

/**
 * PRIORITÉ 2 — Cycle de livraison.
 *
 * La livraison est une étape distincte de la participation : un membre
 * "completed" n'est PAS forcément livré, et un membre "beneficiary" n'est pas
 * forcément livrable. Seule `delivery_status` décrit la livraison.
 *
 * Un commerçant ne peut confirmer une remise que si TOUTES les conditions
 * suivantes sont réunies (contrôle backend, jamais seulement en React) :
 *  1. la tontine porte bien un produit physique (pas une tontine "argent") ;
 *  2. la tontine n'est pas annulée ;
 *  3. le membre a bien été désigné bénéficiaire (beneficiary_round renseigné) ;
 *  4. le round pour lequel il était bénéficiaire est intégralement payé ;
 *  5. le produit est toujours disponible (non archivé) ;
 *  6. la livraison est en attente et pas déjà faite.
 */
class DeliveryService
{
    public function __construct(
        protected TransactionRunner $transactions,
    ) {}

    /**
     * Raisons_codes empilées expliquant pourquoi une livraison est refusée.
     * Servent aussi à l'API pour afficher un état explicite côté client.
     *
     * @return array<int, string>
     */
    public function blockers(TontineMember $member): array
    {
        $tontine = $member->tontine;

        $blockers = [];

        if ($tontine->isCashTontine()) {
            $blockers[] = 'cash_tontine';
        }

        if ($tontine->isCancelled()) {
            $blockers[] = 'tontine_cancelled';
        }

        if (! $member->hasBeneficiaryRound()) {
            $blockers[] = 'not_beneficiary';
        }

        if (! in_array($member->status, [TontineMember::STATUS_BENEFICIARY, TontineMember::STATUS_COMPLETED], true)) {
            $blockers[] = 'turn_not_active';
        }

        if ($member->beneficiary_round !== null && ! $this->roundIsPaid($tontine, $member->beneficiary_round)) {
            $blockers[] = 'round_not_paid';
        }

        if ($member->isDelivered()) {
            $blockers[] = 'already_delivered';
        } elseif (! $member->isAwaitingDelivery()) {
            $blockers[] = 'not_awaiting_delivery';
        }

        if (! $this->productIsAvailable($tontine)) {
            $blockers[] = 'product_unavailable';
        }

        return $blockers;
    }

    public function isEligible(TontineMember $member): bool
    {
        return $this->blockers($member) === [];
    }

    /**
     * Garde-fou appelé par les endpoints de livraison (web + API).
     * 409 = conflit avec l'état courant du cycle métier.
     */
    public function assertEligible(TontineMember $member): void
    {
        $blockers = $this->blockers($member);

        if ($blockers !== []) {
            abort(409, $this->messageFor($blockers));
        }
    }

    /**
     * Confirme la remise. Transactionnel : la ligne est verrouillée puis
     * re-validée, ce qui rend l'opération idempotente (une double confirmation
     * est refusée, pas dupliquée).
     */
    public function confirm(TontineMember $member): TontineMember
    {
        $confirmed = $this->transactions->run(function () use ($member) {
            $locked = TontineMember::query()->lockForUpdate()->findOrFail($member->id);
            $locked->setRelation('tontine', Tontine::query()->findOrFail($locked->tontine_id));

            $this->assertEligible($locked);

            $locked->markDelivered();

            return $locked->fresh(['tontine', 'user']);
        });

        $confirmed->user->notify(new DeliveryConfirmedNotification($confirmed));

        return $confirmed;
    }

    /**
     * Le round est payé si chaque membre contributeur a une cotisation completed.
     */
    public function roundIsPaid(Tontine $tontine, int $round): bool
    {
        $expected = (int) $tontine->contributingMembers()->count();

        if ($expected === 0) {
            return false;
        }

        $paid = (int) Contribution::where('round', $round)
            ->where('status', Contribution::STATUS_COMPLETED)
            ->whereHas('tontineMember', fn ($query) => $query
                ->where('tontine_id', $tontine->id)
                ->where('status', '!=', TontineMember::STATUS_WITHDRAWN))
            ->count();

        return $paid >= $expected;
    }

    /**
     * File d'attente d'un commerçant : ce qui est livrable maintenant, ce qui
     * attend encore le paiement du round, ce qui est déjà livré.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function queueForMerchant(int $merchantId): Collection
    {
        return TontineMember::with(['user', 'tontine.product'])
            ->where(function ($query) {
                $query->whereIn('delivery_status', [
                    TontineMember::DELIVERY_AWAITING_PAYMENT,
                    TontineMember::DELIVERY_PENDING,
                    TontineMember::DELIVERY_DELIVERED,
                ]);
            })
            ->whereHas('tontine.product', fn ($query) => $query->where('merchant_id', $merchantId))
            ->orderByRaw("CASE delivery_status
                WHEN 'pending' THEN 0
                WHEN 'awaiting_payment' THEN 1
                ELSE 2 END")
            ->orderByDesc('id')
            ->get()
            ->map(function (TontineMember $member) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'user_name' => $member->user->name,
                    'status' => $member->status,
                    'beneficiary_round' => $member->beneficiary_round,
                    'delivery_status' => $member->delivery_status,
                    'delivered_at' => $member->delivered_at?->toIso8601String(),
                    'tontine' => [
                        'id' => $member->tontine->id,
                        'name' => $member->tontine->name,
                        'status' => $member->tontine->status,
                        'current_round' => $member->tontine->current_round,
                        'product' => $member->tontine->product ? [
                            'id' => $member->tontine->product->id,
                            'name' => $member->tontine->product->name,
                        ] : null,
                    ],
                    'is_eligible' => $this->isEligible($member),
                    'blockers' => $this->blockers($member),
                ];
            });
    }

    private function productIsAvailable(Tontine $tontine): bool
    {
        if ($tontine->isCashTontine()) {
            return false;
        }

        $product = $tontine->product;

        if (! $product instanceof Product) {
            return false;
        }

        return $product->status !== 'archived';
    }

    private function messageFor(array $blockers): string
    {
        return match (true) {
            in_array('cash_tontine', $blockers, true) => 'Une tontine argent ne donne lieu à aucune livraison de produit.',
            in_array('tontine_cancelled', $blockers, true) => 'Cette tontine a été annulée : aucune livraison ne peut être confirmée.',
            in_array('not_beneficiary', $blockers, true) => 'Ce membre n\'a pas été désigné bénéficiaire.',
            in_array('turn_not_active', $blockers, true) => 'Le tour de ce membre n\'est pas actif.',
            in_array('round_not_paid', $blockers, true) => 'Le round du bénéficiaire n\'est pas intégralement payé : livraison impossible.',
            in_array('already_delivered', $blockers, true) => 'Cette livraison a déjà été confirmée.',
            in_array('not_awaiting_delivery', $blockers, true) => 'Cette livraison n\'est pas encore éligible.',
            in_array('product_unavailable', $blockers, true) => 'Le produit de cette tontine n\'est plus disponible.',
            default => 'Cette livraison n\'est pas autorisée.',
        };
    }
}
