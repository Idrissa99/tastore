<?php

namespace Tests\Support\Concerns;

use App\Models\Contribution;
use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TontineService;
use Illuminate\Support\Collection;

/**
 * Constructeurs de scénarios pour les tests du cycle métier.
 * Chaque helper produit un état cohérent avec les règles réelles de
 * l'application (états séparés, contributions réellement payées, etc.).
 */
trait BuildsTontineCycles
{
    /**
     * Tontine produit avec N membres, activée, round 1 en cours, appel de
     * cotisation généré, premier membre désigné bénéficiaire.
     *
     * @return array{0: Tontine, 1: TontineMember, 2: Collection<int, TontineMember>}
     */
    protected function makeActiveProductTontine(int $memberCount = 2, ?Merchant $merchant = null): array
    {
        $merchant ??= Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'published',
        ]);

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'type' => 'product',
            'max_members' => $memberCount,
            'contribution_amount' => 10000,
            'commission_rate' => 0.10,
            'status' => Tontine::STATUS_ACTIVE,
            'current_round' => 1,
        ]);

        $members = collect();

        for ($position = 1; $position <= $memberCount; $position++) {
            $members->push(TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => User::factory()->create()->id,
                'position' => $position,
                'status' => TontineMember::STATUS_ACTIVE,
                'delivery_status' => TontineMember::DELIVERY_NOT_APPLICABLE,
            ]));
        }

        app(TontineService::class)->generateContributionCallForRound($tontine, 1);
        $beneficiary = app(TontineService::class)->designateBeneficiary($tontine, 1);

        return [
            $tontine->fresh(),
            $beneficiary->fresh(),
            $members->map(fn (TontineMember $member) => $member->fresh())->values(),
        ];
    }

    /**
     * Tontine argent : aucun produit physique, aucune livraison possible.
     *
     * @return array{0: Tontine, 1: TontineMember}
     */
    protected function makeActiveCashTontine(int $memberCount = 2): array
    {
        $tontine = Tontine::factory()->create([
            'product_id' => null,
            'type' => 'cash',
            'max_members' => $memberCount,
            'contribution_amount' => 5000,
            'commission_rate' => 0.10,
            'status' => Tontine::STATUS_ACTIVE,
            'current_round' => 1,
        ]);

        $members = collect();

        for ($position = 1; $position <= $memberCount; $position++) {
            $members->push(TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => User::factory()->create()->id,
                'position' => $position,
                'status' => TontineMember::STATUS_ACTIVE,
            ]));
        }

        app(TontineService::class)->generateContributionCallForRound($tontine, 1);
        $beneficiary = app(TontineService::class)->designateBeneficiary($tontine, 1);

        return [$tontine->fresh(), $beneficiary->fresh()];
    }

    /**
     * Paie une cotisation donnée via le gateway, puis renvoie la cotisation
     * dans son état en base.
     */
    protected function payContribution(Contribution $contribution): Contribution
    {
        app(PaymentService::class)->payContribution($contribution, 'orange_money');

        return $contribution->fresh();
    }

    /**
     * Paie toutes les cotisations d'un round, sans déclencher de livraison.
     */
    protected function payRound(Tontine $tontine, int $round): void
    {
        foreach ($this->roundContributions($tontine, $round) as $contribution) {
            $this->payContribution($contribution);
        }
    }

    /**
     * @return Collection<int, Contribution>
     */
    protected function roundContributions(Tontine $tontine, int $round)
    {
        return Contribution::where('round', $round)
            ->whereHas('tontineMember', fn ($query) => $query->where('tontine_id', $tontine->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Force l'état d'une cotisation sans passer par le gateway (utile pour
     *ixture des scénarios "failed" / "cancelled").
     */
    protected function forceContributionStatus(Contribution $contribution, string $status): Contribution
    {
        $contribution->update([
            'status' => $status,
            'paid_at' => $status === Contribution::STATUS_COMPLETED ? ($contribution->paid_at ?? now()) : null,
        ]);

        return $contribution->fresh();
    }

    /**
     * Achat par tranches minimal pour les tests de rapports financiers.
     */
    protected function makeInstallmentPurchase(Product $product, User $user, int $count = 2, float $rate = 0.10): InstallmentPurchase
    {
        $purchase = InstallmentPurchase::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'installments_count' => $count,
            'installment_amount' => 10000,
            'product_price' => 20000,
            'status' => 'active',
            'delivery_status' => 'not_applicable',
        ]);

        for ($number = 1; $number <= $count; $number++) {
            Installment::create([
                'installment_purchase_id' => $purchase->id,
                'installment_number' => $number,
                'amount' => 10000,
                'commission_rate' => $rate,
                'currency' => 'XOF',
                'status' => Installment::STATUS_PENDING,
            ]);
        }

        return $purchase;
    }

    protected function forceInstallmentStatus(Installment $installment, string $status, ?float $commission = null): Installment
    {
        $installment->update(array_filter([
            'status' => $status,
            'paid_at' => $status === Installment::STATUS_COMPLETED ? ($installment->paid_at ?? now()) : null,
            'commission_amount' => $commission,
        ], fn ($value) => $value !== null));

        return $installment->fresh();
    }

    protected function merchantUser(array $attributes = []): User
    {
        return Merchant::factory()->create(array_merge(['status' => 'approved'], $attributes))->user;
    }
}
