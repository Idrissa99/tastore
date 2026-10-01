<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\RefundService;
use App\Services\TontineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 §8 — Remboursements de bout en bout.
 *
 * Aucun remboursement opérateur n'est simulé : le mode "manual" signifie que
 * l'admin déclare un règlement effectué hors plateforme.
 */
class RefundTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_only_a_paid_contribution_is_refundable(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $refunds = app(RefundService::class);

        $this->assertFalse($refunds->isRefundable($contribution));
        $this->assertContains('contribution_not_paid', $refunds->blockers($contribution));

        $this->forceContributionStatus($contribution, Contribution::STATUS_FAILED);
        $this->assertFalse($refunds->isRefundable($contribution->fresh()));

        $this->forceContributionStatus($contribution, Contribution::STATUS_CANCELLED);
        $this->assertFalse($refunds->isRefundable($contribution->fresh()));

        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);
        $this->assertTrue($refunds->isRefundable($contribution->fresh()));
    }

    public function test_creating_a_refund_for_an_unpaid_contribution_throws(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();

        $this->expectException(\RuntimeException::class);

        app(RefundService::class)->createFor($contribution, $tontine);
    }

    public function test_a_contribution_can_only_be_refunded_once(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);

        $refunds = app(RefundService::class);
        $first = $refunds->createFor($contribution, $tontine);
        $second = $refunds->createFor($contribution->fresh(), $tontine);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Refund::count());

        $this->assertFalse($refunds->isRefundable($contribution->fresh()));
        $this->assertContains('already_refunded', $refunds->blockers($contribution->fresh()));
    }

    public function test_database_refuses_a_second_refund_for_the_same_contribution(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);
        $member = $contribution->tontineMember;

        Refund::create([
            'contribution_id' => $contribution->id,
            'tontine_id' => $tontine->id,
            'user_id' => $member->user_id,
            'amount' => $contribution->amount,
            'status' => Refund::STATUS_PENDING,
        ]);

        $this->expectException(QueryException::class);

        Refund::create([
            'contribution_id' => $contribution->id,
            'tontine_id' => $tontine->id,
            'user_id' => $member->user_id,
            'amount' => $contribution->amount,
            'status' => Refund::STATUS_PENDING,
        ]);
    }

    public function test_a_refund_cannot_be_processed_twice(): void
    {
        $admin = $this->admin();
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);

        $refund = app(RefundService::class)->createFor($contribution, $tontine);

        $this->actingAs($admin)
            ->postJson("/api/admin/refunds/{$refund->id}/process")
            ->assertOk()
            ->assertJsonPath('status', Refund::STATUS_PROCESSED);

        $firstProcessedAt = $refund->fresh()->processed_at;
        $this->assertSame($admin->id, $refund->fresh()->processed_by);
        $this->assertSame(Refund::METHOD_MANUAL, $refund->fresh()->method);

        // Deuxième traitement : refusé, horodatage inchangé.
        $this->actingAs($admin)
            ->postJson("/api/admin/refunds/{$refund->id}/process")
            ->assertStatus(409);

        $this->assertEquals($firstProcessedAt, $refund->fresh()->processed_at);
    }

    public function test_web_refund_processing_is_also_single_use(): void
    {
        $admin = $this->admin();
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);

        $refund = app(RefundService::class)->createFor($contribution, $tontine);

        $this->actingAs($admin)
            ->post(route('admin.refunds.process', $refund))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.refunds.process', $refund))
            ->assertStatus(409);

        $this->assertSame(Refund::STATUS_PROCESSED, $refund->fresh()->status);
    }

    public function test_refund_processing_records_an_optional_manual_reference(): void
    {
        $admin = $this->admin();
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);

        $refund = app(RefundService::class)->createFor($contribution, $tontine);

        $this->actingAs($admin)
            ->postJson("/api/admin/refunds/{$refund->id}/process", [
                'payment_reference' => 'VIR-2026-0001',
            ])
            ->assertOk()
            ->assertJsonPath('payment_reference', 'VIR-2026-0001');

        // Aucune référence inventée : c'est une trace de règlement manuel.
        $this->assertSame(Refund::METHOD_MANUAL, $refund->fresh()->method);
    }

    public function test_refund_amount_mirrors_the_paid_contribution(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $this->payContribution($this->roundContributions($tontine, 1)->first());
        $this->payContribution($this->roundContributions($tontine, 1)->last());

        $tontine->refresh();
        $tontine->update(['status' => Tontine::STATUS_ACTIVE, 'current_round' => 1]);

        app(TontineService::class)->cancelTontine($tontine->fresh());

        Refund::get()->each(function (Refund $refund) use ($tontine) {
            $this->assertEquals(
                $refund->contribution->amount,
                $refund->amount
            );
            $this->assertSame($tontine->id, $refund->tontine_id);
            $this->assertSame($refund->contribution->tontineMember->user_id, $refund->user_id);
        });

        $this->assertSame(2, Refund::count());
        $this->assertEquals(20000, (float) Refund::sum('amount'));
    }

    public function test_refundable_state_is_exposed_on_the_contribution_model(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();

        $this->assertFalse($contribution->isRefundable());
        $this->assertFalse($contribution->isPaid());

        $this->forceContributionStatus($contribution, Contribution::STATUS_COMPLETED);
        $this->assertTrue($contribution->fresh()->isRefundable());
        $this->assertTrue($contribution->fresh()->isPaid());

        app(RefundService::class)->createFor($contribution->fresh(), $tontine);
        $this->assertFalse($contribution->fresh()->isRefundable());
    }

    public function test_refund_list_exposes_manual_method_without_any_operator_reference(): void
    {
        $admin = $this->admin();
        [$tontine] = $this->makeActiveProductTontine(2);
        $this->payContribution($this->roundContributions($tontine, 1)->first());
        $this->payContribution($this->roundContributions($tontine, 1)->last());

        $tontine->refresh();
        app(TontineService::class)->cancelTontine($tontine->fresh());

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/refunds')
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['data']);

        foreach ($response['data'] as $row) {
            $this->assertSame(Refund::METHOD_MANUAL, $row['method']);
            $this->assertNull($row['payment_reference']);
            $this->assertNull($row['processed_at']);
        }
    }

    public function test_merchants_and_tontine_members_are_not_affected_by_refund_creation(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $this->payRound($tontine, 1);

        $tontine->refresh();
        $tontine->update(['status' => Tontine::STATUS_ACTIVE, 'current_round' => 1]);

        app(TontineService::class)->cancelTontine($tontine->fresh());

        $this->assertSame(Tontine::STATUS_CANCELLED, $tontine->fresh()->status);
        $this->assertSame(2, Refund::count());
        $this->assertSame(
            [TontineMember::STATUS_COMPLETED, TontineMember::STATUS_WITHDRAWN],
            $tontine->fresh()->members()->orderBy('position')->pluck('status')->all()
        );
    }
}
