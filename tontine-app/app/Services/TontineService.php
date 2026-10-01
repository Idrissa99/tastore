<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Dispute;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Notifications\BecameBeneficiaryNotification;
use App\Services\Payments\PaymentException;

/**
 * PRIORITÉ 2 — Cycle métier d'une tontine.
 *
 * Quatre états volontairement séparés :
 *  - tontine.status        : open -> active -> completed | cancelled
 *  - membre.status         : active -> beneficiary -> completed | withdrawn
 *  - contribution.status   : pending -> completed | failed | cancelled
 *  - membre.delivery_status: not_applicable -> awaiting_payment -> pending -> delivered
 *
 * "completed" ne veut PAS dire "livré", et "beneficiary" ne veut PAS dire
 * "livrable" : la livraison exige en plus que le round soit financé.
 */
class TontineService
{
    public function __construct(
        protected RefundService $refundService,
        protected TransactionRunner $transactions,
        protected RotationDraw $rotationDraw,
    ) {}

    /**
     * Si la tontine vient d'atteindre son nombre max de membres :
     * on l'active, on TIRE l'ordre de passage, puis on désigne le premier
     * bénéficiaire et on lance le round 1.
     *
     * L'ordre de passage est tiré ici, et nulle part ailleurs, parce que
     * c'est le seul instant où il est connu de tout le monde : la tontine est
     * pleine, donc plus personne ne peut la rejoindre et fausser le tirage.
     */
    public function activateIfFull(Tontine $tontine): void
    {
        $this->transactions->run(function () use ($tontine) {
            $lockedTontine = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            if ($lockedTontine->status !== Tontine::STATUS_OPEN || ! $lockedTontine->isFull()) {
                return;
            }

            $lockedTontine->update([
                'status' => Tontine::STATUS_ACTIVE,
                'current_round' => 1,
            ]);

            // AVANT designateBeneficiary() : le bénéficiaire du round 1 est le
            // membre en position 1 APRÈS le tirage, pas celui arrivé en tête.
            $this->drawRotationOrder($lockedTontine);

            $this->generateContributionCallForRound($lockedTontine, 1);
            $this->designateBeneficiary($lockedTontine, 1);
        });
    }

    /**
     * Tire l'ordre de passage définitif et le réattribue aux membres.
     *
     * Appelé une seule fois par tontine : le garde-fou `status === open` de
     * activateIfFull() rend toute deuxième exécution sans effet, puisque le
     * statut est déjà passé à `active`.
     *
     * RÉÉCRITURE EN DEUX TEMPS — contrainte d'index, pas de style
     *
     * `tontine_members (tontine_id, position)` est UNIQUE. Réattribuer les
     * positions une par une violerait cet index dès le premier échange — tirer
     * [3, 1, 2] impose d'abord de poser 3 là où vit le 1, ce qui entre en
     * collision immédiatement. `position` est nullable et l'index traite tous
     * les NULL comme distincts : on libère donc toutes les positions, puis on
     * réattribue. Un membre sans position est seulement injoignable, ce qui
     * n'a aucune incidence au milieu de cette transaction.
     */
    private function drawRotationOrder(Tontine $tontine): void
    {
        $members = $tontine->members()->orderBy('position')->orderBy('id')->get();

        if ($members->isEmpty()) {
            return;
        }

        $order = $this->rotationDraw->draw($members);

        TontineMember::where('tontine_id', $tontine->id)->update(['position' => null]);

        foreach ($order as $memberId => $position) {
            TontineMember::where('tontine_id', $tontine->id)
                ->whereKey($memberId)
                ->update(['position' => $position]);
        }
    }

    /**
     * Désigne le bénéficiaire du round donné, selon l'ordre de passage.
     *
     * Un seul bénéficiaire actif par tontine : si un bénéficiaire existe déjà,
     * la méthode le renvoie sans en désigner un second (protection applicative
     * doublée par un index unique partiel en base).
     */
    public function designateBeneficiary(Tontine $tontine, ?int $round = null): ?TontineMember
    {
        $round ??= (int) $tontine->current_round;

        return $this->transactions->run(function () use ($tontine, $round) {
            $lockedTontine = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            if (in_array($lockedTontine->status, [Tontine::STATUS_CANCELLED, Tontine::STATUS_COMPLETED], true)) {
                return null;
            }

            $existing = $lockedTontine->members()
                ->where('status', TontineMember::STATUS_BENEFICIARY)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $next = $lockedTontine->contributingMembers()
                ->where('status', TontineMember::STATUS_ACTIVE)
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            if (! $next) {
                return null;
            }

            $next->update([
                'status' => TontineMember::STATUS_BENEFICIARY,
                'beneficiary_round' => $round,
                // Pas encore livrable : le round vient d'être créé, il n'est pas payé.
                'delivery_status' => $lockedTontine->isCashTontine()
                    ? TontineMember::DELIVERY_NOT_APPLICABLE
                    : TontineMember::DELIVERY_AWAITING_PAYMENT,
            ]);

            $next->user->notify(new BecameBeneficiaryNotification($lockedTontine));

            return $next->fresh(['tontine', 'user']);
        });
    }

    /**
     * Crée l'appel de cotisation (une ligne "pending") pour CHAQUE membre encore
     * participant, pour le round donné — y compris ceux déjà passés, car dans
     * une tontine tout le monde continue à cotiser. Idempotent.
     *
     * PRIORITÉ 4 §7 — l'ensemble est dans UNE transaction : sans cela, une
     * erreur au milieu (contrainte unique, exception) laisserait un round
     * partiellement généré, donc des membres sans appel de cotisation. La
     * transaction est courte et ne contient aucun appel externe.
     *
     * @return int nombre de lignes réellement créées
     */
    public function generateContributionCallForRound(Tontine $tontine, ?int $round = null): int
    {
        $round ??= (int) $tontine->current_round;

        if ($tontine->isCancelled()) {
            return 0;
        }

        return $this->transactions->run(function () use ($tontine, $round) {
            $members = $tontine->contributingMembers()->orderBy('position')->get();
            $created = 0;

            foreach ($members as $member) {
                $contribution = Contribution::firstOrCreate(
                    [
                        'tontine_member_id' => $member->id,
                        'round' => $round,
                    ],
                    [
                        'amount' => $tontine->contribution_amount,
                        'commission_rate' => $tontine->commission_rate ?? $this->currentCommissionRate(),
                        'currency' => config('payments.currency', 'XOF'),
                        'payment_method' => 'mobile_money',
                        'status' => Contribution::STATUS_PENDING,
                    ]
                );

                if ($contribution->wasRecentlyCreated) {
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * Marque une cotisation comme payée, prélève la commission figée sur la
     * transaction, puis vérifie si le round est bouclé.
     */
    public function recordPayment(
        Contribution $contribution,
        ?string $reference = null,
        ?float $paidAmount = null,
        ?string $currency = null,
    ): Contribution {
        return $this->transactions->run(function () use ($contribution, $reference, $paidAmount, $currency) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            $expectedCurrency = strtoupper($locked->currency ?: config('payments.currency', 'XOF'));

            if ($paidAmount !== null && round((float) $paidAmount, 2) !== round((float) $locked->amount, 2)) {
                throw new PaymentException('Le montant reçu ne correspond pas au montant attendu.', 422);
            }

            if ($currency !== null && strtoupper($currency) !== $expectedCurrency) {
                throw new PaymentException('La devise reçue ne correspond pas à la devise attendue.', 422);
            }

            if ($reference !== null && $reference !== '' && $locked->transaction_reference
                && $locked->transaction_reference !== $reference
                && ! str_starts_with($locked->transaction_reference, 'PENDING-')) {
                throw new PaymentException('La transaction ne correspond pas à ce paiement.', 409);
            }

            if ($reference !== null && $reference !== '' && Contribution::query()
                ->where('transaction_reference', $reference)
                ->where('id', '!=', $locked->id)
                ->exists()) {
                throw new PaymentException('Cette transaction est déjà associée à un autre paiement.', 409);
            }

            if ($locked->status === Contribution::STATUS_COMPLETED) {
                return $locked;
            }

            if ($locked->status === Contribution::STATUS_CANCELLED) {
                throw new PaymentException('Cette cotisation a été annulée.', 409);
            }

            if ($locked->isPendingVerification()) {
                throw new PaymentException('Un code de transfert est déjà en attente de vérification.', 409);
            }

            $commissionRate = $locked->commission_rate !== null
                ? (float) $locked->commission_rate
                : $this->currentCommissionRate();

            $updates = [
                'status' => Contribution::STATUS_COMPLETED,
                'paid_at' => now(),
                'commission_amount' => round((float) $locked->amount * $commissionRate, 2),
            ];

            if ($reference !== null && $reference !== '') {
                $updates['transaction_reference'] = $reference;
            }

            $locked->update($updates);

            $this->checkRoundCompletionAndAdvance($locked->tontineMember->tontine);

            return $locked->fresh();
        });
    }

    public function recordFailure(Contribution $contribution, ?string $reason = null): Contribution
    {
        return $this->transactions->run(function () use ($contribution, $reason) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if ($locked->status === Contribution::STATUS_COMPLETED
                || $locked->status === Contribution::STATUS_CANCELLED) {
                return $locked;
            }

            $locked->update([
                'status' => Contribution::STATUS_FAILED,
                'payment_failure_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Point d'entrée unique du changement de round.
     *
     * Appelé après chaque encaissement. Ne fait rien tant que le round courant
     * n'est pas intégralement payé, et ne peut pas s'exécuter deux fois pour le
     * même round : le verrou sur la tontine + le contrôle "round toujours
     * courant et toujours financé" rendent l'opération idempotente.
     */
    public function checkRoundCompletionAndAdvance(Tontine $tontine): void
    {
        $this->transactions->run(function () use ($tontine) {
            $lockedTontine = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            if ($lockedTontine->status !== Tontine::STATUS_ACTIVE) {
                return;
            }

            $round = (int) $lockedTontine->current_round;

            if (! $lockedTontine->roundIsFunded($round)) {
                return;
            }

            $this->closeRound($lockedTontine, $round);
        });
    }

    /**
     * Clôture du round financé :
     *  - le bénéficiaire sortant devient "completed" mais reste livrable s'il ne
     *    l'a pas encore été (son tour s'est terminé, la remise non) ;
     *  - le suivant devient bénéficiaire du round suivant, en attente de paiement ;
     *  - si personne ne reste, la tontine est terminée.
     */
    private function closeRound(Tontine $tontine, int $round): void
    {
        $outgoing = $tontine->members()
            ->where('status', TontineMember::STATUS_BENEFICIARY)
            ->lockForUpdate()
            ->first();

        if ($outgoing && $outgoing->beneficiary_round === $round) {
            $updates = ['status' => TontineMember::STATUS_COMPLETED];

            // Le round est financé : le bénéficiaire devient livrable, même si
            // le round suivant démarre avant que le commerçant livre.
            if (! $tontine->isCashTontine()
                && $outgoing->delivery_status === TontineMember::DELIVERY_AWAITING_PAYMENT) {
                $updates['delivery_status'] = TontineMember::DELIVERY_PENDING;
            }

            $outgoing->update($updates);
        }

        $next = $this->designateBeneficiary($tontine, $round + 1);

        if (! $next) {
            $tontine->update(['status' => Tontine::STATUS_COMPLETED]);

            return;
        }

        $tontine->update(['current_round' => $round + 1]);
        $this->generateContributionCallForRound($tontine, $round + 1);
    }

    /**
     * Annule une tontine (décision admin) :
     *  - la tontine passe à "cancelled" et ne demande plus de cotisation ;
     *  - les cotisations "pending" et "failed" passent à "cancelled" (elles ne
     *    pourront plus jamais être payées) ;
     *  - chaque cotisation encaissée ("completed") génère UNE obligation de
     *    remboursement manuelle, idempotente ;
     *  - les membres dont le tour n'a pas eu lieu passent à "withdrawn" et plus
     *    aucune livraison n'est attendue d'eux.
     *
     * L'argent n'est pas déplacé par le code : l'admin déclare manuellement
     * chaque restitution dans /admin/refunds (aucun opérateur n'est connecté).
     */
    public function cancelTontine(Tontine $tontine): void
    {
        $this->transactions->run(function () use ($tontine) {
            $lockedTontine = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            if ($lockedTontine->status === Tontine::STATUS_CANCELLED) {
                return;
            }

            if ($lockedTontine->status === Tontine::STATUS_COMPLETED) {
                throw new PaymentException('Impossible d\'annuler une tontine terminée.', 409);
            }

            $lockedTontine->update(['status' => Tontine::STATUS_CANCELLED]);

            foreach ($lockedTontine->members()->lockForUpdate()->get() as $member) {
                $updates = [];

                if (in_array($member->status, [TontineMember::STATUS_ACTIVE, TontineMember::STATUS_BENEFICIARY], true)) {
                    $updates['status'] = TontineMember::STATUS_WITHDRAWN;
                }

                // Une livraison déjà faite reste faite ; sinon plus rien n'est dû.
                if ($member->delivery_status !== TontineMember::DELIVERY_DELIVERED) {
                    $updates['delivery_status'] = TontineMember::DELIVERY_NOT_APPLICABLE;
                }

                if ($updates !== []) {
                    $member->update($updates);
                }
            }

            $contributions = Contribution::whereHas(
                'tontineMember',
                fn ($query) => $query->where('tontine_id', $lockedTontine->id)
            )->lockForUpdate()->get();

            foreach ($contributions as $contribution) {
                if ($contribution->isPaid()) {
                    $this->refundService->createFor($contribution, $lockedTontine);

                    continue;
                }

                if (in_array($contribution->status, [
                    Contribution::STATUS_PENDING,
                    Contribution::STATUS_FAILED,
                ], true)) {
                    $contribution->update(['status' => Contribution::STATUS_CANCELLED]);
                }
            }
        });
    }

    /**
     * Ce qui empêche une tontine d être supprimée, en phrases affichables.
     *
     * Renvoie une liste VIDE quand la suppression est possible. Les contrôleurs
     * lajoinient directement dans leur message, et le client peut l'afficher
     * telle quelle : pas de couche de traduction, pas de codes à traduire.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(Tontine $tontine): array
    {
        $blockers = [];

        // Cotisations ENCAISSÉES. C'est le seul contrôle qui compte vraiment :
        // une cotisation "pending" ou "failed" n'a pas d'argent derrière elle,
        // donc sa disparition ne détruit aucun document comptable.
        $paid = Contribution::whereHas('tontineMember', fn ($query) => $query->where('tontine_id', $tontine->id))
            ->where('status', Contribution::STATUS_COMPLETED)
            ->count();

        if ($paid > 0) {
            $blockers[] = "{$paid} cotisation(s) déjà encaissée(s)";
        }

        // Un remboursement implique forcément une cotisation encaissée, donc
        // celui-ci est redondant en pratique — mais il est vérifié séparément
        // pour qu'une anomalie de données ne supprime pas la trace d'un
        // remboursement déjà traité.
        $refunds = Refund::where('tontine_id', $tontine->id)->count();

        if ($refunds > 0) {
            $blockers[] = "{$refunds} remboursement(s) enregistré(s)";
        }

        // Un litige est une réclamation : sa résolution est une trace d'audit.
        $disputes = Dispute::where('tontine_id', $tontine->id)->count();

        if ($disputes > 0) {
            $blockers[] = "{$disputes} litige(s) ouvert(s)";
        }

        // Un avis laisse au marchand une note ; le supprimer en cascade
        // laisserait merchants.rating désynchronisé pour toujours, car cette
        // colonne n'est recalculée qu'à la création d'un avis.
        $reviews = $tontine->merchantReviews()->count();

        if ($reviews > 0) {
            $blockers[] = "{$reviews} avis laissé(s)";
        }

        return $blockers;
    }

    public function deletionRefusalMessage(array $blockers): string
    {
        return 'Suppression impossible : '.implode(', ', $blockers)
            .'. Annule plutôt la tontine : les traces financières doivent être conservées.';
    }

    /**
     * Supprime définitivement une tontine — et, par cascade, ses membres, ses
     * cotisations, ses remboursements, ses litiges et ses avis.
     *
     * LE CONTRÔLE EST REFAIT SOUS VERROU, ET C'EST TOUT L'INTÉRÊT
     *
     * Toutes les clés étrangères qui pointent vers `tontines` sont en CASCADE,
     * sans un seul RESTRICT : la base ne refusera rien et n'émettera aucun
     * avertissement. Le garde-fou est donc applicatif, et il n'a de valeur que
     * s'il est rejoué dans la transaction qui supprime, sinon un paiement
     * encaissé entre le contrôle et le DELETE serait emporté en silence.
     *
     * LIMITE ASSUMÉE : un contrôle « la ligne existe-t-elle ? » ne verrouille
     * rien quand il ne trouve rien, si bien qu'une-course reste théoriquement
     * possible entre deux transactions concurrentes sur cette base. C'est la
     * même limite que celle de cancelTontine() et de l'annulation des
     * livraisons ; la fermer supposerait de passer au niveau isolations, ce
     * que le projet ne fait nulle part.
     */
    public function deleteTontine(Tontine $tontine): void
    {
        $this->transactions->run(function () use ($tontine) {
            $lockedTontine = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            $blockers = $this->deletionBlockers($lockedTontine);

            abort_if($blockers !== [], 409, $this->deletionRefusalMessage($blockers));

            $lockedTontine->delete();
        });
    }

    private function currentCommissionRate(): float
    {
        return (float) Setting::get('commission_rate', config('commissions.rate', 0));
    }
}
