<?php

namespace Tests\Feature\Financial;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_of_completed_tontine_can_review_merchant(): void
    {
        $merchant = Merchant::factory()->create();
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $user = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
            'status' => 'completed',
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'completed',
        ]);

        $this->actingAs($user)->post(route('merchants.review', $tontine), [
            'rating' => 5,
            'comment' => 'Très fiable',
        ])->assertRedirect();

        $this->assertSame(1, $merchant->reviews()->count());
        $this->assertEquals(5, $merchant->fresh()->rating);
    }

    public function test_non_member_cannot_review_merchant(): void
    {
        $merchant = Merchant::factory()->create();
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $creator = User::factory()->create();
        $outsider = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $creator->id,
            'status' => 'completed',
        ]);

        $this->actingAs($outsider)->post(route('merchants.review', $tontine), [
            'rating' => 5,
        ])->assertForbidden();
    }

    public function test_cannot_review_before_tontine_is_completed(): void
    {
        $merchant = Merchant::factory()->create();
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $user = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'active',
        ]);

        $this->actingAs($user)->post(route('merchants.review', $tontine), [
            'rating' => 5,
        ])->assertStatus(409);
    }

    public function test_admin_cancellation_creates_refunds_for_paid_contributions(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
            'contribution_amount' => 5000,
            'status' => 'active',
            'current_round' => 1,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'beneficiary',
        ]);

        $contribution = \App\Models\Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 5000,
            'payment_method' => 'card',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.tontines.cancel', $tontine))->assertRedirect();

        $refund = Refund::where('contribution_id', $contribution->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(5000, $refund->amount);
        $this->assertSame('pending', $refund->status);
    }

    public function test_admin_can_mark_a_refund_as_processed(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'active',
        ]);

        $contribution = \App\Models\Contribution::create([
            'tontine_member_id' => $member->id, 'round' => 1, 'amount' => 1000,
            'payment_method' => 'card', 'status' => 'completed', 'paid_at' => now(),
        ]);

        $refund = Refund::create([
            'contribution_id' => $contribution->id, 'tontine_id' => $tontine->id,
            'user_id' => $user->id, 'amount' => 1000, 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.refunds.process', $refund))
            ->assertRedirect();

        $this->assertSame('processed', $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->processed_at);
    }

    public function test_commission_is_calculated_when_contribution_is_paid(): void
    {
        config(['commissions.rate' => 0.03]);

        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
            'contribution_amount' => 1000,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'beneficiary',
        ]);

        $contribution = \App\Models\Contribution::create([
            'tontine_member_id' => $member->id, 'round' => 1, 'amount' => 1000,
            'payment_method' => 'card', 'status' => 'pending',
        ]);

        $this->actingAs($user)->post(route('contributions.pay', $contribution), [
            'payment_method' => 'card',
        ])->assertSessionDoesntHaveErrors();

        $this->assertEquals(30, $contribution->fresh()->commission_amount);
    }
}
