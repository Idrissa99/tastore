<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Installment;
use App\Models\Merchant;
use App\Models\Refund;
use App\Models\Tontine;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * PRIORITÉ 2 — Source unique de vérité pour tous les montants financiers.
 *
 * Règles :
 *  - les cotisations (tontines) et les tranches (achats) ne sont JAMAIS mélangées
 *    sans être identifiées : chaque source est retournée séparément ;
 *  - les commissions proviennent TOUJOURS de la colonne gelée
 *    `commission_amount` de la transaction, jamais d'un taux global actuel ;
 *  - seules les lignes "completed" comptent comme encaissé.
 */
class FinancialReportService
{
    public function forPeriod(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return $this->build(
            $this->contributionQuery($from, $to),
            $this->installmentQuery($from, $to),
            $this->refundQuery($from, $to)
        );
    }

    /**
     * Totaux historiques (sans filtre de période).
     */
    public function allTime(): array
    {
        return $this->build(
            $this->contributionQuery(),
            $this->installmentQuery(),
            $this->refundQuery()
        );
    }

    /**
     * Ventilation des commissions encaissées par commerçant, toutes sources
     * identifiées. Les tontines "argent" (sans commerçant) sont isolées.
     */
    public function byMerchant(): array
    {
        $merchants = Merchant::all()->map(function (Merchant $merchant) {
            $contributions = $this->merchantContributions($merchant);
            $installments = $this->merchantInstallments($merchant);

            return [
                'merchant' => $merchant,
                'contributions_count' => $contributions->count(),
                'installments_count' => $installments->count(),
                'total_collected' => round(
                    $contributions->sum('amount') + $installments->sum('amount'), 2
                ),
                'total_commission' => round(
                    $contributions->sum('commission_amount') + $installments->sum('commission_amount'), 2
                ),
            ];
        })->filter(fn ($row) => $row['contributions_count'] + $row['installments_count'] > 0)
            ->sortByDesc('total_commission')
            ->values();

        $cashContributions = $this->cashContributions();

        return [
            'merchants' => $merchants,
            'cash' => [
                'contributions_count' => $cashContributions->count(),
                'installments_count' => 0,
                'total_collected' => round($cashContributions->sum('amount'), 2),
                'total_commission' => round($cashContributions->sum('commission_amount'), 2),
            ],
        ];
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function merchantContributions(Merchant $merchant): Collection
    {
        return Contribution::where('status', Contribution::STATUS_COMPLETED)
            ->whereHas(
                'tontineMember.tontine.product',
                fn ($query) => $query->where('merchant_id', $merchant->id)
            )->get();
    }

    /**
     * @return Collection<int, Installment>
     */
    public function merchantInstallments(Merchant $merchant): Collection
    {
        return Installment::where('status', Installment::STATUS_COMPLETED)
            ->whereHas(
                'purchase.product',
                fn ($query) => $query->where('merchant_id', $merchant->id)
            )->get();
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function cashContributions(): Collection
    {
        return Contribution::where('status', Contribution::STATUS_COMPLETED)
            ->whereHas('tontineMember.tontine', fn ($query) => $query->where('type', 'cash'))
            ->get();
    }

    /**
     * Encaissements d'un commerçant, toutes sources identifiées.
     */
    public function merchantRevenue(Merchant $merchant): array
    {
        $contributions = $this->merchantContributions($merchant);
        $installments = $this->merchantInstallments($merchant);

        return [
            'contributions' => round($contributions->sum('amount'), 2),
            'installments' => round($installments->sum('amount'), 2),
            'total' => round($contributions->sum('amount') + $installments->sum('amount'), 2),
        ];
    }

    private function build($contributions, $installments, $refunds): array
    {
        $contributionCollected = round($contributions->sum('amount'), 2);
        $contributionCommission = round($contributions->sum('commission_amount'), 2);
        $installmentCollected = round($installments->sum('amount'), 2);
        $installmentCommission = round($installments->sum('commission_amount'), 2);

        return [
            // Cotisations de tontine
            'contributions' => [
                'count' => $contributions->count(),
                'collected' => $contributionCollected,
                'commission' => $contributionCommission,
            ],
            // Tranches d'achats individuels
            'installments' => [
                'count' => $installments->count(),
                'collected' => $installmentCollected,
                'commission' => $installmentCommission,
            ],
            // Somme explicite des deux sources, jamais un mélange non identifié
            'totals' => [
                'collected' => round($contributionCollected + $installmentCollected, 2),
                'commission' => round($contributionCommission + $installmentCommission, 2),
                'count' => $contributions->count() + $installments->count(),
            ],
            'refunds' => [
                'count' => $refunds->count(),
                'pending_count' => $refunds->where('status', Refund::STATUS_PENDING)->count(),
                'pending_amount' => round($refunds->where('status', Refund::STATUS_PENDING)->sum('amount'), 2),
                'processed_count' => $refunds->where('status', Refund::STATUS_PROCESSED)->count(),
                'processed_amount' => round($refunds->where('status', Refund::STATUS_PROCESSED)->sum('amount'), 2),
            ],
        ];
    }

    private function contributionQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null)
    {
        return Contribution::where('status', Contribution::STATUS_COMPLETED)
            ->when($from && $to, fn ($query) => $query->whereBetween('paid_at', [$from, $to]))
            ->get();
    }

    private function installmentQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null)
    {
        return Installment::where('status', Installment::STATUS_COMPLETED)
            ->when($from && $to, fn ($query) => $query->whereBetween('paid_at', [$from, $to]))
            ->get();
    }

    private function refundQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null)
    {
        return Refund::query()
            ->when($from && $to, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
            ->get();
    }

    /**
     * Lignes d'export CSV : chaque ligne porte sa source pour que montants
     * de cotisations et de tranches ne soient jamais confondus à la lecture.
     */
    public function exportRows(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $contributions = Contribution::with('tontineMember.user', 'tontineMember.tontine')
            ->where('status', Contribution::STATUS_COMPLETED)
            ->when($from && $to, fn ($query) => $query->whereBetween('paid_at', [$from, $to]))
            ->get()
            ->map(fn (Contribution $contribution) => [
                'source' => 'contribution',
                'date' => $contribution->paid_at?->format('Y-m-d H:i'),
                'utilisateur' => $contribution->tontineMember->user->name,
                'reference' => $contribution->tontineMember->tontine->name,
                'detail' => 'Tontine — round '.$contribution->round,
                'montant' => $contribution->amount,
                'commission' => $contribution->commission_amount,
                'taux_commission' => $contribution->commission_rate,
                'moyen_de_paiement' => $contribution->payment_method,
            ]);

        $installments = Installment::with('purchase.user', 'purchase.product')
            ->where('status', Installment::STATUS_COMPLETED)
            ->when($from && $to, fn ($query) => $query->whereBetween('paid_at', [$from, $to]))
            ->get()
            ->map(fn (Installment $installment) => [
                'source' => 'installment',
                'date' => $installment->paid_at?->format('Y-m-d H:i'),
                'utilisateur' => $installment->purchase->user->name,
                'reference' => $installment->purchase->product->name,
                'detail' => 'Achat par tranches — tranche '.$installment->installment_number,
                'montant' => $installment->amount,
                'commission' => $installment->commission_amount,
                'taux_commission' => $installment->commission_rate,
                'moyen_de_paiement' => $installment->payment_method,
            ]);

        return $contributions->concat($installments)
            ->sortByDesc('date')
            ->values();
    }

    /**
     * Tontines dont le round courant est financé — utilisé par les tests et le
     * tableau de bord pour afficher la cohérence du cycle.
     */
    public function tontinesWithFundedRound(): Collection
    {
        return Tontine::where('status', Tontine::STATUS_ACTIVE)
            ->get()
            ->filter(fn (Tontine $tontine) => $tontine->roundIsFunded($tontine->current_round))
            ->values();
    }
}
