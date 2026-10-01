<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogAndProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_only_published_products(): void
    {
        Product::factory()->create(['name' => 'Visible', 'status' => 'published']);
        Product::factory()->create(['name' => 'Caché', 'status' => 'draft']);

        $response = $this->get(route('products.show-all'));

        $response->assertOk()->assertSee('Visible')->assertDontSee('Caché');
    }

    public function test_catalog_can_be_filtered_by_search_term(): void
    {
        Product::factory()->create(['name' => 'Téléphone Android', 'status' => 'published']);
        Product::factory()->create(['name' => 'Panneau solaire', 'status' => 'published']);

        $response = $this->get(route('products.show-all', ['q' => 'Téléphone']));

        $response->assertOk()->assertSee('Téléphone Android')->assertDontSee('Panneau solaire');
    }

    public function test_user_can_update_their_profile(): void
    {
        $user = User::factory()->create(['name' => 'Ancien Nom', 'phone' => '90000000']);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'Nouveau Nom',
            'phone' => '90111111',
        ])->assertRedirect();

        $this->assertSame('Nouveau Nom', $user->fresh()->name);
        $this->assertSame('90111111', $user->fresh()->phone);
    }

    public function test_user_can_change_their_password_with_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'phone' => $user->phone,
            'current_password' => 'old-password',
            'new_password' => 'new-password123',
            'new_password_confirmation' => 'new-password123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_password_change_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'phone' => $user->phone,
            'current_password' => 'wrong-password',
            'new_password' => 'new-password123',
            'new_password_confirmation' => 'new-password123',
        ])->assertSessionHasErrors('current_password');
    }
}
