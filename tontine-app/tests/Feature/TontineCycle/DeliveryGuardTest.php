<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 §3 — Protection contre la livraison prématurée.
 *
 * Les contrôles sont vérifiés côté API (JSON), pas seulement sur le rendu
 * React : masquer le bouton ne protège rien.
 */
class DeliveryGuardTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    public function test_api_refuses_delivery_before_the_round_is_paid(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $beneficiary->delivery_status);

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$beneficiary->id}/deliver")
            ->assertStatus(409);

        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $beneficiary->fresh()->delivery_status);
        $this->assertNull($beneficiary->fresh()->delivered_at);
    }

    public function test_api_refuses_delivery_when_only_some_members_have_paid(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(3, $merchant);

        $this->payContribution($this->roundContributions($tontine, 1)->first());

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$beneficiary->id}/deliver")
            ->assertStatus(409);

        $this->assertFalse($beneficiary->fresh()->isDelivered());
    }

    public function test_api_refuses_delivery_for_a_member_who_is_not_the_beneficiary(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);

        $nonBeneficiary = $tontine->fresh()->members()
            ->where('status', TontineMember::STATUS_BENEFICIARY)
            ->firstOrFail();

        $this->assertNotSame($beneficiary->id, $nonBeneficiary->id);

        // L'ancien bénéficiaire (status completed) reste livrable, le nouveau
        // ne l'est pas tant que son round n'est pas payé.
        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$nonBeneficiary->id}/deliver")
            ->assertStatus(409);

        $blockers = app(DeliveryService::class)->blockers($nonBeneficiary);
        $this->assertContains('round_not_paid', $blockers);
    }

    public function test_api_refuses_delivery_for_a_plain_active_member(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(3, $merchant);

        $this->payRound($tontine, 1);

        $stillActive = $tontine->fresh()->members()
            ->where('status', TontineMember::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($stillActive);

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$stillActive->id}/deliver")
            ->assertStatus(409);

        $blockers = app(DeliveryService::class)->blockers($stillActive);
        $this->assertContains('not_beneficiary', $blockers);
        $this->assertContains('turn_not_active', $blockers);
    }

    public function test_api_refuses_delivery_for_a_withdrawn_member(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();
        $this->assertTrue(app(DeliveryService::class)->isEligible($outgoing));

        $outgoing->update(['status' => TontineMember::STATUS_WITHDRAWN]);

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertStatus(409);

        $this->assertFalse($outgoing->fresh()->isDelivered());
    }

    public function test_api_refuses_delivery_when_the_product_is_archived(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();
        $tontine->product->update(['status' => 'archived']);

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertStatus(409);

        $this->assertContains(
            'product_unavailable',
            app(DeliveryService::class)->blockers($outgoing->fresh())
        );
        $this->assertFalse($outgoing->fresh()->isDelivered());
    }

    public function test_api_refuses_delivery_when_the_tontine_is_cancelled(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);
        $outgoing = $beneficiary->fresh();

        app(TontineService::class)->cancelTontine($tontine->fresh());

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertStatus(409);

        $this->assertFalse($outgoing->fresh()->isDelivered());
    }

    public function test_api_refuses_delivery_twice(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);
        $outgoing = $beneficiary->fresh();

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertOk()
            ->assertJsonPath('delivery_status', 'delivered');

        $firstDeliveredAt = $outgoing->fresh()->delivered_at;

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertStatus(409);

        $this->assertEquals($firstDeliveredAt, $outgoing->fresh()->delivered_at);
    }

    public function test_api_refuses_delivery_for_another_merchants_product(): void
    {
        $owner = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $owner);

        $this->payRound($tontine, 1);
        $outgoing = $beneficiary->fresh();

        $intruder = Merchant::factory()->create(['status' => 'approved'])->user;

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertStatus(403);

        $this->assertFalse($outgoing->fresh()->isDelivered());
    }

    public function test_api_allows_delivery_for_a_funded_beneficiary(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);
        $outgoing = $beneficiary->fresh();

        $this->assertTrue(app(DeliveryService::class)->isEligible($outgoing));

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertOk()
            ->assertJsonPath('delivery_status', 'delivered');

        $this->assertSame(TontineMember::DELIVERY_DELIVERED, $outgoing->fresh()->delivery_status);
        $this->assertNotNull($outgoing->fresh()->delivered_at);
    }

    public function test_api_delivery_still_allowed_after_the_tontine_is_completed(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(1, $merchant);

        $this->payRound($tontine, 1);

        $tontine->refresh();
        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);

        $outgoing = $beneficiary->fresh();
        $this->assertSame(TontineMember::STATUS_COMPLETED, $outgoing->status);

        $this->actingAs($merchant->user, 'sanctum')
            ->postJson("/api/merchant/orders/{$outgoing->id}/deliver")
            ->assertOk();

        $this->assertSame(TontineMember::DELIVERY_DELIVERED, $outgoing->fresh()->delivery_status);
    }

    public function test_api_orders_endpoint_separates_ready_from_waiting_for_payment(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $response = $this->actingAs($merchant->user, 'sanctum')
            ->getJson('/api/merchant/orders')
            ->assertOk();

        $rows = $response->json();
        $this->assertCount(1, $rows);
        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $rows[0]['delivery_status']);
        $this->assertFalse($rows[0]['is_eligible']);
        $this->assertContains('round_not_paid', $rows[0]['blockers']);
        $this->assertSame(1, $rows[0]['beneficiary_round']);

        // Dashboard : rien de livrable, un bénéficiaire en attente de paiement.
        $dashboard = $this->actingAs($merchant->user, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertOk()
            ->json();

        $this->assertCount(0, $dashboard['pending_deliveries_tontine']);
        $this->assertCount(1, $dashboard['waiting_for_payment_tontine']);

        $this->payRound($tontine, 1);

        $rows = $this->actingAs($merchant->user, 'sanctum')
            ->getJson('/api/merchant/orders')
            ->assertOk()
            ->json();

        $this->assertTrue($rows[0]['is_eligible']);
        $this->assertSame([], $rows[0]['blockers']);

        $dashboard = $this->actingAs($merchant->user, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertOk()
            ->json();

        $this->assertCount(1, $dashboard['pending_deliveries_tontine']);
    }

    public function test_web_route_also_refuses_premature_delivery(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->actingAs($merchant->user)
            ->from(route('merchant.orders.index'))
            ->post(route('merchant.orders.deliver', $beneficiary))
            ->assertStatus(409);

        $this->assertFalse($beneficiary->fresh()->isDelivered());
    }

    public function test_web_orders_page_renders_waiting_and_ready_states(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->actingAs($merchant->user)
            ->get(route('merchant.orders.index'))
            ->assertOk()
            ->assertSee('Bénéficiaire d')
            ->assertSee('pas encore intégralement payé');

        $this->payRound($tontine, 1);

        $this->actingAs($merchant->user)
            ->get(route('merchant.orders.index'))
            ->assertOk()
            ->assertSee('Prêt à être livré')
            ->assertSee('Confirmer la livraison');
    }

    public function test_cash_tontine_members_never_appear_in_a_merchant_queue(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveCashTontine(2);

        $this->assertTrue($tontine->isCashTontine());

        $rows = $this->actingAs($merchant->user, 'sanctum')
            ->getJson('/api/merchant/orders')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_delivery_requires_a_product_backed_tontine(): void
    {
        // Une tontine sans produit n'a rien à livrer, même "active".
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => null,
            'type' => 'cash',
            'status' => Tontine::STATUS_ACTIVE,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => TontineMember::STATUS_BENEFICIARY,
            'beneficiary_round' => 1,
            'delivery_status' => TontineMember::DELIVERY_PENDING,
        ]);

        Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 1000,
            'commission_rate' => 0.1,
            'status' => Contribution::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        $this->assertTrue($tontine->fresh()->roundIsFunded(1));

        $blockers = app(DeliveryService::class)->blockers($member);
        $this->assertContains('cash_tontine', $blockers);
        $this->assertContains('product_unavailable', $blockers);
        $this->assertFalse(app(DeliveryService::class)->isEligible($member));
    }
}
