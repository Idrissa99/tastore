<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstallmentPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_installment_resource_exposes_manual_verification_fields(): void
    {
        $product = Product::factory()->create(['price' => 20000, 'status' => 'published']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $purchase = $this->postJson("/api/produits/{$product->id}/tranches", [
            'installments_count' => 2,
        ])->assertCreated()->json('data');

        $purchaseId = $purchase['id'];
        $installmentId = $purchase['installments'][0]['id'];

        $this->getJson("/api/mes-achats/{$purchaseId}")
            ->assertOk()
            ->assertJsonPath('data.installments.0.verification_status', 'not_applicable')
            ->assertJsonPath('data.installments.0.transfer_code', null)
            ->assertJsonPath('data.installments.0.submitted_at', null);

        $this->postJson("/api/tranches/{$installmentId}/soumettre-code", [
            'payment_method' => 'mynita',
            'transfer_code' => 'TRANCHE-123',
        ])->assertOk()
            ->assertJsonPath('data.installments.0.verification_status', 'pending')
            ->assertJsonPath('data.installments.0.transfer_code', 'TRANCHE-123');

        $this->postJson("/api/tranches/{$installmentId}/pay", [
            'payment_method' => 'orange_money',
        ])->assertStatus(409);

        $this->assertDatabaseHas('installments', [
            'id' => $installmentId,
            'status' => 'pending',
            'verification_status' => 'pending',
        ]);
    }
}
