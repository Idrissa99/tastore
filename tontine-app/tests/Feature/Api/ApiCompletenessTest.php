<?php

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\InstallmentPurchase;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_endpoints_work_without_authentication(): void
    {
        $product = Product::factory()->create(['status' => 'published']);

        $this->getJson('/api/produits')->assertOk();
        $this->getJson("/api/produits/{$product->id}")->assertOk()
            ->assertJsonPath('data.id', $product->id);

        $this->getJson("/api/commercants/{$product->merchant_id}")->assertOk();
    }

    public function test_authenticated_user_can_create_and_pay_an_installment_purchase_via_api(): void
    {
        config(['commissions.rate' => 0.03]);

        $product = Product::factory()->create(['price' => 20000, 'status' => 'published']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 2]);
        $response->assertCreated()->assertJsonPath('data.installments_count', 2);

        $purchaseId = $response->json('data.id');

        $this->getJson('/api/mes-achats')->assertOk()->assertJsonCount(1, 'data');

        $installmentId = $response->json('data.installments.0.id');

        $this->postJson("/api/tranches/{$installmentId}/pay", ['payment_method' => 'card'])
            ->assertOk();
    }

    public function test_client_cannot_create_tontine_via_api(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);

        $this->postJson('/api/tontines', [
            'type' => 'product',
            'product_id' => $product->id,
            'name' => 'Test',
            'frequency' => 'monthly',
            'max_members' => 3,
        ])->assertForbidden();
    }

    public function test_user_can_raise_a_dispute_via_api(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/tontines/{$tontine->id}/disputes", [
            'subject' => 'Test',
            'description' => 'Description test',
        ])->assertCreated();

        $this->assertDatabaseHas('disputes', ['tontine_id' => $tontine->id, 'raised_by' => $user->id]);
    }

    public function test_user_can_list_and_mark_notifications_as_read_via_api(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $user->notify(new \App\Notifications\BecameBeneficiaryNotification($tontine));

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')->assertOk();

        $notificationId = $user->notifications()->first()->id;

        $this->postJson("/api/notifications/{$notificationId}/read")->assertOk();
        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_user_can_update_profile_via_api(): void
    {
        $user = User::factory()->create(['name' => 'Ancien Nom']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profil', [
            'name' => 'Nouveau Nom',
            'phone' => '90999888',
        ])->assertOk()->assertJsonPath('name', 'Nouveau Nom');
    }

    public function test_merchant_can_manage_products_via_api(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        Sanctum::actingAs($merchant->user);

        $response = $this->postJson('/api/merchant/products', [
            'name' => 'Produit API',
            'price' => 15000,
            'stock' => 5,
            'status' => 'published',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Produit API');

        $this->getJson('/api/merchant/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_merchant_can_confirm_installment_delivery_via_api(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => 'published']);
        $buyer = User::factory()->create();

        $purchase = InstallmentPurchase::create([
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'installments_count' => 1,
            'installment_amount' => 10300,
            'product_price' => 10000,
            'status' => 'completed',
            'delivery_status' => 'pending',
        ]);

        Sanctum::actingAs($merchant->user);

        $this->postJson("/api/merchant/installment-orders/{$purchase->id}/deliver")
            ->assertOk();

        $this->assertSame('delivered', $purchase->fresh()->delivery_status);
    }

    public function test_blocked_user_is_rejected_on_api_requests(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk();

        // `is_blocked` n'est pas fillable : affectation directe, comme en production.
        $user->is_blocked = true;
        $user->save();

        $this->getJson('/api/me')->assertForbidden();
    }
}
