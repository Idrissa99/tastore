<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TontineCreationRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cannot_access_tontine_creation_page(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->get(route('tontines.create'))
            ->assertForbidden();
    }

    public function test_client_cannot_submit_tontine_creation(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $product = Product::factory()->create(['status' => 'published']);

        $this->actingAs($client)
            ->post(route('tontines.store'), [
                'type' => 'product',
                'product_id' => $product->id,
                'name' => 'Test',
                'frequency' => 'monthly',
                'max_members' => 3,
            ])
            ->assertForbidden();
    }

    public function test_approved_merchant_can_create_a_tontine_on_their_own_product(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => 'published', 'price' => 30000]);

        $this->actingAs($merchant->user)
            ->post(route('tontines.store'), [
                'type' => 'product',
                'product_id' => $product->id,
                'name' => 'Tontine Test',
                'frequency' => 'monthly',
                'max_members' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tontines', ['name' => 'Tontine Test', 'product_id' => $product->id]);
    }

    public function test_merchant_cannot_create_a_tontine_on_another_merchants_product(): void
    {
        $ownerMerchant = Merchant::factory()->create(['status' => 'approved']);
        $otherMerchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $ownerMerchant->id, 'status' => 'published']);

        $this->actingAs($otherMerchant->user)
            ->post(route('tontines.store'), [
                'type' => 'product',
                'product_id' => $product->id,
                'name' => 'Test',
                'frequency' => 'monthly',
                'max_members' => 3,
            ])
            ->assertForbidden();
    }

    public function test_unapproved_merchant_cannot_create_a_tontine(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => 'published']);

        $this->actingAs($merchant->user)
            ->get(route('tontines.create'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_cash_tontine(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('tontines.store'), [
                'type' => 'cash',
                'total_amount' => 50000,
                'name' => 'Tontine Argent Test',
                'frequency' => 'monthly',
                'max_members' => 5,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tontines', ['name' => 'Tontine Argent Test', 'type' => 'cash']);
    }

    public function test_merchant_cannot_create_a_cash_tontine(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);

        $this->actingAs($merchant->user)
            ->post(route('tontines.store'), [
                'type' => 'cash',
                'total_amount' => 50000,
                'name' => 'Test',
                'frequency' => 'monthly',
                'max_members' => 5,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_a_tontine_on_any_merchants_product(): void
    {
        $admin = User::factory()->admin()->create();
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => 'published']);

        $this->actingAs($admin)
            ->post(route('tontines.store'), [
                'type' => 'product',
                'product_id' => $product->id,
                'name' => 'Test Admin',
                'frequency' => 'monthly',
                'max_members' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tontines', ['name' => 'Test Admin']);
    }

    public function test_client_can_still_join_an_existing_tontine(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);

        $tontine = \App\Models\Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $merchant->user->id,
            'max_members' => 3,
        ]);

        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->post(route('tontines.join', $tontine))
            ->assertRedirect();

        $this->assertDatabaseHas('tontine_members', ['tontine_id' => $tontine->id, 'user_id' => $client->id]);
    }
}
