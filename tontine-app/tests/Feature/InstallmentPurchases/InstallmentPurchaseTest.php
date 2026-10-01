<?php

namespace Tests\Feature\InstallmentPurchases;

use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InstallmentDeliveryConfirmedNotification;
use App\Notifications\InstallmentPurchaseCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InstallmentPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_an_installment_purchase_with_computed_amount(): void
    {
        config(['commissions.rate' => 0.03]);

        $product = Product::factory()->create(['price' => 30000, 'status' => 'published']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('installment-purchases.store', $product), [
            'installments_count' => 3,
        ])->assertRedirect();

        $purchase = InstallmentPurchase::first();

        $this->assertNotNull($purchase);
        $this->assertSame(3, $purchase->installments_count);
        // 30000 / 3 = 10000, x1.03 = 10300
        $this->assertEquals(10300, $purchase->installment_amount);
        $this->assertSame(3, $purchase->installments()->count());
    }

    public function test_paying_all_installments_completes_the_purchase_and_notifies_buyer(): void
    {
        Notification::fake();

        $product = Product::factory()->create(['price' => 20000, 'status' => 'published']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('installment-purchases.store', $product), [
            'installments_count' => 2,
        ]);

        $purchase = InstallmentPurchase::first();

        foreach ($purchase->installments as $installment) {
            $this->actingAs($user)->post(route('installments.pay', $installment), [
                'payment_method' => 'orange_money',
            ])->assertSessionDoesntHaveErrors();
        }

        $this->assertSame('completed', $purchase->fresh()->status);
        $this->assertSame('pending', $purchase->fresh()->delivery_status);

        Notification::assertSentTo($user, InstallmentPurchaseCompletedNotification::class);
    }

    public function test_purchase_stays_active_until_all_installments_are_paid(): void
    {
        $product = Product::factory()->create(['price' => 20000, 'status' => 'published']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('installment-purchases.store', $product), [
            'installments_count' => 2,
        ]);

        $purchase = InstallmentPurchase::first();
        $firstInstallment = $purchase->installments()->first();

        $this->actingAs($user)->post(route('installments.pay', $firstInstallment), [
            'payment_method' => 'card',
        ]);

        $this->assertSame('active', $purchase->fresh()->status);
    }

    public function test_other_user_cannot_pay_someone_elses_installment(): void
    {
        $product = Product::factory()->create(['price' => 10000, 'status' => 'published']);
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($owner)->post(route('installment-purchases.store', $product), [
            'installments_count' => 2,
        ]);

        $installment = InstallmentPurchase::first()->installments()->first();

        $this->actingAs($outsider)
            ->post(route('installments.pay', $installment), ['payment_method' => 'card'])
            ->assertForbidden();
    }

    public function test_merchant_can_confirm_delivery_once_purchase_is_completed(): void
    {
        Notification::fake();

        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 10000, 'status' => 'published']);
        $user = User::factory()->create();

        $purchase = InstallmentPurchase::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'installments_count' => 1,
            'installment_amount' => 10300,
            'product_price' => 10000,
            'status' => 'completed',
            'delivery_status' => 'pending',
        ]);

        $this->actingAs($merchant->user)
            ->post(route('merchant.installment-orders.deliver', $purchase))
            ->assertRedirect();

        $this->assertSame('delivered', $purchase->fresh()->delivery_status);
        Notification::assertSentTo($user, InstallmentDeliveryConfirmedNotification::class);
    }

    public function test_merchant_cannot_confirm_delivery_for_another_merchants_purchase(): void
    {
        $ownerMerchant = Merchant::factory()->create(['status' => 'approved']);
        $otherMerchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $ownerMerchant->id, 'status' => 'published']);
        $user = User::factory()->create();

        $purchase = InstallmentPurchase::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'installments_count' => 1,
            'installment_amount' => 10300,
            'product_price' => 10000,
            'status' => 'completed',
            'delivery_status' => 'pending',
        ]);

        $this->actingAs($otherMerchant->user)
            ->post(route('merchant.installment-orders.deliver', $purchase))
            ->assertForbidden();
    }
}
