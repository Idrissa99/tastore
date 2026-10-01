<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\Payments\PaymentException;
use App\Services\PaymentService;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 §1 — Annulation d'une tontine.
 *
 * Cohérence exigée entre tontine / contribution / membre / remboursement,
 * quel que soit l'état de chaque cotisation (pending, completed, failed,
 * cancelled).
 */
class TontineCancellationTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_cancelling_a_tontine_with_pending_contributions_cancels_them(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $contributions = $this->roundContributions($tontine, 1);

        $this->assertSame(Contribution::STATUS_PENDING, $contributions->first()->status);

        app(TontineService::class)->cancelTontine($tontine);

        $tontine->refresh();
        $this->assertSame(Tontine::STATUS_CANCELLED, $tontine->status);

        // Toutes les cotisations non payées passent à "cancelled".
        $this->assertSame(
            [Contribution::STATUS_CANCELLED, Contribution::STATUS_CANCELLED],
            $this->roundContributions($tontine, 1)->pluck('status')->all()
        );

        // Aucune livraison n'est plus attendue, aucun remboursement créé.
        $this->assertSame(0, Refund::count());
        $tontine->members()->get()->each(function (TontineMember $member) {
            $this->assertSame(TontineMember::STATUS_WITHDRAWN, $member->status);
            $this->assertSame(TontineMember::DELIVERY_NOT_APPLICABLE, $member->delivery_status);
        });
    }

    public function test_cancelling_a_tontine_with_completed_contributions_creates_exactly_one_refund_each(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $contributions = $this->roundContributions($tontine, 1);

        $this->payContribution($contributions[0]);
        $this->payContribution($contributions->last());

        $tontine->refresh();
        $this->assertSame(2, $tontine->current_round);

        app(TontineService::class)->cancelTontine($tontine);

        $this->assertSame(Tontine::STATUS_CANCELLED, $tontine->fresh()->status);

        // Les 2 cotisations payées du round 1 donnent 2 remboursements...
        $this->assertSame(2, Refund::count());
        $this->assertSame(2, Refund::where('status', Refund::STATUS_PENDING)->count());

        // ...et les 2 cotisations pending du round 2 sont annulées, pas remboursées.
        $this->assertSame(
            2,
            Contribution::where('status', Contribution::STATUS_CANCELLED)->count()
        );
        $this->assertSame(
            [Contribution::STATUS_COMPLETED, Contribution::STATUS_COMPLETED],
            $this->roundContributions($tontine, 1)->pluck('status')->all()
        );

        Refund::get()->each(function (Refund $refund) {
            $this->assertSame(Refund::METHOD_MANUAL, $refund->method);
            $this->assertNull($refund->processed_at);
        });
    }

    public function test_cancelling_a_tontine_with_failed_contributions_cancels_them(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contributions = $this->roundContributions($tontine, 1);

        $this->forceContributionStatus($contributions[0], Contribution::STATUS_FAILED);
        $this->forceContributionStatus($contributions[1], Contribution::STATUS_FAILED);

        app(TontineService::class)->cancelTontine($tontine);

        $this->assertSame(
            [Contribution::STATUS_CANCELLED, Contribution::STATUS_CANCELLED],
            $this->roundContributions($tontine, 1)->pluck('status')->all()
        );
        $this->assertSame(0, Refund::count());
    }

    public function test_mixed_contribution_states_stay_coherent_after_cancellation(): void
    {
        [$tontine] = $this->makeActiveProductTontine(3);
        $contributions = $this->roundContributions($tontine, 1);

        $this->payContribution($contributions[0]);
        $this->forceContributionStatus($contributions[1], Contribution::STATUS_FAILED);
        // $contributions[2] reste "pending"

        app(TontineService::class)->cancelTontine($tontine);

        $statuses = $this->roundContributions($tontine, 1)->pluck('status')->all();
        sort($statuses);

        $this->assertSame(
            [Contribution::STATUS_CANCELLED, Contribution::STATUS_CANCELLED, Contribution::STATUS_COMPLETED],
            $statuses
        );

        // Seul le paiement encaissé donne lieu à un remboursement.
        $this->assertSame(1, Refund::count());
        $this->assertEquals(
            $contributions[0]->amount,
            Refund::first()->amount
        );
    }

    public function test_a_cancelled_contribution_can_never_be_paid_again(): void
    {
        Notification::fake();

        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();

        $user = $contribution->tontineMember->user;
        $this->assertTrue($contribution->isPayable());

        app(TontineService::class)->cancelTontine($tontine);
        $cancelled = $contribution->fresh();

        $this->assertTrue($cancelled->isCancelled());
        $this->assertFalse($cancelled->isPayable());

        // Endpoint de paiement
        $this->actingAs($user)
            ->postJson("/api/contributions/{$cancelled->id}/pay", ['payment_method' => 'orange_money'])
            ->assertStatus(409);

        $this->assertSame(Contribution::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertNull($cancelled->fresh()->paid_at);

        // Service de paiement
        $this->expectException(PaymentException::class);
        app(PaymentService::class)->payContribution($cancelled->fresh(), 'orange_money');
    }

    public function test_a_cancelled_contribution_rejects_a_transfer_code(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $contribution = $this->roundContributions($tontine, 1)->first();
        $user = $contribution->tontineMember->user;

        app(TontineService::class)->cancelTontine($tontine);

        $this->actingAs($user)
            ->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
                'payment_method' => 'bank',
                'transfer_code' => 'ABC-123',
            ])
            ->assertStatus(409);

        $this->assertNotSame('pending', $contribution->fresh()->verification_status);
    }

    public function test_cancellation_is_idempotent_and_does_not_duplicate_refunds(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $this->payContribution($this->roundContributions($tontine, 1)->first());
        $this->payContribution($this->roundContributions($tontine, 1)->last());

        $tontine->refresh();
        $this->assertSame(2, $tontine->current_round);

        $service = app(TontineService::class);
        $service->cancelTontine($tontine);
        $firstCount = Refund::count();
        $this->assertSame(2, $firstCount);

        // Deux annulations supplémentaires : aucun nouvel effet.
        $service->cancelTontine($tontine->fresh());
        $service->cancelTontine($tontine->fresh());

        $this->assertSame(Tontine::STATUS_CANCELLED, $tontine->fresh()->status);
        $this->assertSame($firstCount, Refund::count());
        $this->assertSame(2, Contribution::where('status', Contribution::STATUS_CANCELLED)->count());
    }

    public function test_a_completed_tontine_cannot_be_cancelled(): void
    {
        [$tontine] = $this->makeActiveProductTontine(1);
        $this->payRound($tontine, 1);

        $tontine->refresh();
        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/tontines/{$tontine->id}/cancel")
            ->assertStatus(409);
    }

    public function test_cancelling_an_open_tontine_closes_it_without_refunds(): void
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'status' => Tontine::STATUS_OPEN,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => TontineMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/tontines/{$tontine->id}/cancel")
            ->assertOk();

        $this->assertSame(Tontine::STATUS_CANCELLED, $tontine->fresh()->status);
        $this->assertSame(0, Refund::count());
        $this->assertSame(
            TontineMember::STATUS_WITHDRAWN,
            $tontine->fresh()->members()->first()->status
        );
    }

    public function test_a_merchants_pending_delivery_is_dropped_after_cancellation(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();
        $this->assertSame(TontineMember::DELIVERY_PENDING, $outgoing->delivery_status);
        $this->assertTrue(app(DeliveryService::class)->isEligible($outgoing));

        app(TontineService::class)->cancelTontine($tontine->fresh());

        $outgoing = $outgoing->fresh();
        $this->assertSame(TontineMember::STATUS_COMPLETED, $outgoing->status);
        $this->assertSame(TontineMember::DELIVERY_NOT_APPLICABLE, $outgoing->delivery_status);

        $blockers = app(DeliveryService::class)->blockers($outgoing);
        $this->assertContains('tontine_cancelled', $blockers);
    }

    public function test_a_delivered_member_keeps_its_delivery_after_cancellation(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();
        app(DeliveryService::class)->confirm($outgoing);
        $deliveredAt = $outgoing->fresh()->delivered_at;

        app(TontineService::class)->cancelTontine($tontine->fresh());

        $this->assertSame(TontineMember::STATUS_COMPLETED, $outgoing->fresh()->status);
        $this->assertSame(TontineMember::DELIVERY_DELIVERED, $outgoing->fresh()->delivery_status);
        $this->assertEquals($deliveredAt, $outgoing->fresh()->delivered_at);
    }
}
