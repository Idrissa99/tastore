<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Installment;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 §6 & §7 — Commissions et rapports financiers.
 *
 * Règle centrale : tout montant de commission provient de la colonne gelée
 * commission_amount de la transaction, jamais d'un taux global actuel.
 */
class FinancialReportTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_reports_separate_contributions_from_installments(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $contribution = $this->roundContributions($tontine, 1)->first();
        // Taux gelé historique à 7% alors que le taux global est passé à 20%.
        $contribution->update(['commission_rate' => 0.07]);
        $this->payContribution($contribution);
        $this->assertEquals(700, $contribution->fresh()->commission_amount);

        $purchase = $this->makeInstallmentPurchase(
            $tontine->product,
            User::factory()->create(),
            2,
            0.07
        );
        $installments = $purchase->installments;
        $this->forceInstallmentStatus($installments[0], Installment::STATUS_COMPLETED, 700);
        $this->forceInstallmentStatus($installments[1], Installment::STATUS_COMPLETED, 700);

        // Le taux global change APRES : aucun montant ne doit bouger.
        Setting::set('commission_rate', '0.20');

        $stats = app(FinancialReportService::class)->allTime();

        $this->assertSame(1, $stats['contributions']['count']);
        $this->assertEquals(10000, $stats['contributions']['collected']);
        $this->assertEquals(700, $stats['contributions']['commission']);

        $this->assertSame(2, $stats['installments']['count']);
        $this->assertEquals(20000, $stats['installments']['collected']);
        $this->assertEquals(1400, $stats['installments']['commission']);

        $this->assertSame(3, $stats['totals']['count']);
        $this->assertEquals(30000, $stats['totals']['collected']);
        $this->assertEquals(2100, $stats['totals']['commission']);

        // Le total est bien la somme des deux sources, rien de plus.
        $this->assertEquals(
            $stats['contributions']['collected'] + $stats['installments']['collected'],
            $stats['totals']['collected']
        );
        $this->assertEquals(
            $stats['contributions']['commission'] + $stats['installments']['commission'],
            $stats['totals']['commission']
        );
    }

    public function test_api_reports_endpoint_matches_the_service(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['contributions']['count']);
        $this->assertEquals(10000, $response['contributions']['collected']);
        $this->assertEquals(1000, $response['contributions']['commission']);
        $this->assertSame(0, $response['installments']['count']);
        $this->assertEquals($response['contributions']['collected'], $response['totals']['collected']);
    }

    public function test_dashboard_commissions_and_reports_agree(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $purchase = $this->makeInstallmentPurchase($tontine->product, User::factory()->create(), 1);
        $this->forceInstallmentStatus($purchase->installments->first(), Installment::STATUS_COMPLETED, 1000);

        $expected = app(FinancialReportService::class)->allTime();

        $dashboard = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->json();

        $commissions = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/commissions')
            ->assertOk()
            ->json();

        $this->assertEquals($expected['contributions'], $dashboard['contributions']);
        $this->assertEquals($expected['installments'], $dashboard['installments']);
        $this->assertEquals($expected['totals']['commission'], $dashboard['total_commissions']);
        $this->assertEquals($expected['totals']['collected'], $dashboard['total_collected']);

        $this->assertEquals($expected['contributions'], $commissions['contributions']);
        $this->assertEquals($expected['installments'], $commissions['installments']);
        $this->assertEquals($expected['totals']['commission'], $commissions['total_commission']);
        $this->assertEquals($expected['totals']['collected'], $commissions['total_collected']);

        // Le taux global courant (20%) n'entre dans aucun de ces montants.
        Setting::set('commission_rate', '0.20');
        $this->assertEquals(2000, $commissions['total_commission']);
        $this->assertEquals(2000, $commissions['contributions']['commission'] + $commissions['installments']['commission']);
    }

    public function test_merchant_commission_breakdown_identifies_each_source(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $purchase = $this->makeInstallmentPurchase($tontine->product, User::factory()->create(), 2);
        $this->forceInstallmentStatus($purchase->installments[0], Installment::STATUS_COMPLETED, 1000);
        $this->forceInstallmentStatus($purchase->installments[1], Installment::STATUS_COMPLETED, 1000);

        $breakdown = app(FinancialReportService::class)->byMerchant();
        $row = $breakdown['merchants']->firstWhere('merchant.id', $merchant->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['contributions_count']);
        $this->assertSame(2, $row['installments_count']);
        $this->assertEquals(30000, $row['total_collected']);
        $this->assertEquals(3000, $row['total_commission']);

        $revenue = app(FinancialReportService::class)->merchantRevenue($merchant);
        $this->assertEquals(10000, $revenue['contributions']);
        $this->assertEquals(20000, $revenue['installments']);
        $this->assertEquals(30000, $revenue['total']);
    }

    public function test_cash_tontine_commissions_are_reported_separately(): void
    {
        [$tontine] = $this->makeActiveCashTontine(2);
        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $breakdown = app(FinancialReportService::class)->byMerchant();

        $this->assertSame(1, $breakdown['cash']['contributions_count']);
        $this->assertEquals(5000, $breakdown['cash']['total_collected']);
        $this->assertEquals(500, $breakdown['cash']['total_commission']);
        $this->assertSame(0, $breakdown['cash']['installments_count']);

        // Aucun commerçant involved pour une tontine argent.
        $this->assertCount(0, $breakdown['merchants']);
    }

    public function test_commission_amounts_are_not_recalculated_when_the_rate_changes(): void
    {
        Setting::set('commission_rate', '0.05');
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $contribution = $this->roundContributions($tontine, 1)->first();
        $contribution->update(['commission_rate' => 0.12]);
        $this->payContribution($contribution);

        $frozen = $contribution->fresh()->commission_amount;
        $this->assertEquals(1200, $frozen);

        Setting::set('commission_rate', '0.30');

        $stats = app(FinancialReportService::class)->allTime();
        $this->assertEquals(1200, $stats['contributions']['commission']);
        $this->assertEquals(1200, $stats['totals']['commission']);

        // Un nouveau paiement, lui, adopte le nouveau taux.
        $other = $this->roundContributions($tontine->fresh(), 1)->last();
        $other->update(['commission_rate' => 0.30]);
        $this->payContribution($other);

        $stats = app(FinancialReportService::class)->allTime();
        $this->assertEquals(1200 + 3000, $stats['contributions']['commission']);
    }

    public function test_pending_and_failed_contributions_are_not_counted_as_revenue(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(3, $merchant);

        $contributions = $this->roundContributions($tontine, 1);
        $this->payContribution($contributions[0]);
        $this->forceContributionStatus($contributions[1], Contribution::STATUS_FAILED);

        $stats = app(FinancialReportService::class)->allTime();

        $this->assertSame(1, $stats['contributions']['count']);
        $this->assertEquals(10000, $stats['contributions']['collected']);
        $this->assertEquals(1000, $stats['contributions']['commission']);
    }

    public function test_csv_export_labels_every_line_with_its_source_and_frozen_rate(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $contribution = $this->roundContributions($tontine, 1)->first();
        $contribution->update(['commission_rate' => 0.04]);
        $this->payContribution($contribution);

        $purchase = $this->makeInstallmentPurchase($tontine->product, User::factory()->create(), 1, 0.04);
        $this->forceInstallmentStatus($purchase->installments->first(), Installment::STATUS_COMPLETED, 400);

        Setting::set('commission_rate', '0.25');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/reports/export')
            ->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('Source', $lines[0]);
        $this->assertStringContainsString('Taux commission', $lines[0]);
        $this->assertCount(3, $lines);

        $sources = array_map(fn ($line) => explode(';', $line)[0], array_slice($lines, 1));
        sort($sources);
        $this->assertSame(['contribution', 'installment'], $sources);

        $contributionLine = collect(array_slice($lines, 1))
            ->first(fn ($line) => str_starts_with($line, 'contribution'));
        $parts = explode(';', $contributionLine);
        $this->assertEquals('400.00', $parts[6]);
        $this->assertEquals('0.040000', $parts[7]);
    }

    public function test_refund_totals_are_reported_separately_from_revenue(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);

        $tontine->refresh();
        $tontine->update(['status' => Tontine::STATUS_ACTIVE, 'current_round' => 1]);
        app(TontineService::class)->cancelTontine($tontine->fresh());

        $stats = app(FinancialReportService::class)->allTime();

        $this->assertSame(2, $stats['refunds']['pending_count']);
        $this->assertEquals(20000, $stats['refunds']['pending_amount']);
        $this->assertSame(0, $stats['refunds']['processed_count']);
        $this->assertEquals(0.0, $stats['refunds']['processed_amount']);

        // Les cotisations payées restent du chiffre d'affaires encaissé : un
        // remboursement n'est pas un encaissement.
        $this->assertEquals(20000, $stats['contributions']['collected']);

        $processed = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/refunds')
            ->assertOk()
            ->json();

        $this->assertCount(2, $processed['data']);

        foreach ($processed['data'] as $refund) {
            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/refunds/{$refund['id']}/process")
                ->assertOk();
        }

        $stats = app(FinancialReportService::class)->allTime();
        $this->assertSame(0, $stats['refunds']['pending_count']);
        $this->assertSame(2, $stats['refunds']['processed_count']);
        $this->assertEquals(20000, $stats['refunds']['processed_amount']);
    }

    public function test_web_reports_view_shows_both_sources(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $purchase = $this->makeInstallmentPurchase($tontine->product, User::factory()->create(), 1);
        $this->forceInstallmentStatus($purchase->installments->first(), Installment::STATUS_COMPLETED, 1000);

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Cotisations de tontines')
            ->assertSee("Tranches d'achats", false)
            ->assertSee('Total plateforme');
    }

    public function test_web_commissions_view_shows_both_sources(): void
    {
        $admin = $this->admin();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine] = $this->makeActiveProductTontine(2, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $this->actingAs($admin)
            ->get(route('admin.commissions.index'))
            ->assertOk()
            ->assertSee('Cotisations de tontines')
            ->assertSee("Tranches d'achats", false)
            ->assertSee('taux gelé', false);
    }

    public function test_historical_zero_commission_rows_are_preserved_verbatim(): void
    {
        // Deux cotisations anciennes : commission_amount = 0 malgré un
        // commission_rate > 0. Elles restent telles quelles, aucun recalcul.
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'commission_rate' => 0.07,
        ]);

        foreach (range(1, 2) as $ignored) {
            $member = TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => User::factory()->create()->id,
                'position' => $ignored,
                'status' => TontineMember::STATUS_ACTIVE,
            ]);

            Contribution::create([
                'tontine_member_id' => $member->id,
                'round' => 1,
                'amount' => 24281,
                'commission_rate' => 0.07,
                'commission_amount' => 0,
                'status' => Contribution::STATUS_COMPLETED,
                'paid_at' => now(),
            ]);
        }

        Setting::set('commission_rate', '0.20');

        $stats = app(FinancialReportService::class)->allTime();

        $this->assertSame(2, $stats['contributions']['count']);
        $this->assertEquals(48562, $stats['contributions']['collected']);
        $this->assertEquals(0.0, $stats['contributions']['commission']);

        $recomputed = 48562 * 0.20;
        $this->assertGreaterThan(0, $recomputed);
    }
}
