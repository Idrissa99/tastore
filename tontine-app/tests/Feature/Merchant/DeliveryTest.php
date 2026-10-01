<?php

namespace Tests\Feature\Merchant;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_validate_a_delivery_for_their_own_product(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);

        $beneficiaryUser = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $beneficiaryUser->id,
            'contribution_amount' => 10000,
            'current_round' => 2,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $beneficiaryUser->id,
            'position' => 1,
            'status' => 'beneficiary',
            'beneficiary_round' => 1,
            'delivery_status' => 'pending',
        ]);

        // Condition financière : le round 1 du bénéficiaire est intégralement payé.
        \App\Models\Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'commission_rate' => 0.10,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->assertTrue($tontine->fresh()->roundIsFunded(1));

        $response = $this->actingAs($merchant->user)
            ->post(route('merchant.orders.deliver', $member));

        $response->assertRedirect();
        $this->assertSame('delivered', $member->fresh()->delivery_status);
        $this->assertNotNull($member->fresh()->delivered_at);
    }

    public function test_merchant_cannot_validate_delivery_for_another_merchants_product(): void
    {
        $ownerMerchant = Merchant::factory()->create(['status' => 'approved']);
        $otherMerchant = Merchant::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create(['merchant_id' => $ownerMerchant->id]);

        $beneficiaryUser = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $beneficiaryUser->id,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $beneficiaryUser->id,
            'position' => 1,
            'status' => 'beneficiary',
            'delivery_status' => 'pending',
        ]);

        $this->actingAs($otherMerchant->user)
            ->post(route('merchant.orders.deliver', $member))
            ->assertForbidden();

        $this->assertSame('pending', $member->fresh()->delivery_status);
    }

    public function test_client_cannot_access_merchant_space(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->get(route('merchant.dashboard'))
            ->assertForbidden();
    }

    public function test_unapproved_merchant_cannot_access_merchant_space(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);

        $this->actingAs($merchant->user)
            ->get(route('merchant.dashboard'))
            ->assertForbidden();
    }
}
