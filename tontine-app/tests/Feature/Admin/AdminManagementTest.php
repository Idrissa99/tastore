<?php

namespace Tests\Feature\Admin;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_can_archive_a_product(): void
    {
        $product = Product::factory()->create(['status' => 'published']);

        $this->actingAs($this->admin())
            ->post(route('admin.products.archive', $product))
            ->assertRedirect();

        $this->assertSame('archived', $product->fresh()->status);
    }

    public function test_admin_cannot_delete_a_product_linked_to_a_tontine(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();
        Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $creator->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product))
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_admin_can_block_and_unblock_a_user(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.block', $user))->assertRedirect();
        $this->assertTrue($user->fresh()->is_blocked);

        $this->actingAs($admin)->post(route('admin.users.unblock', $user))->assertRedirect();
        $this->assertFalse($user->fresh()->is_blocked);
    }

    public function test_admin_cannot_block_another_admin(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.block', $otherAdmin))
            ->assertForbidden();
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_blocked' => true]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_blocked_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // `is_blocked` n'est pas fillable : affectation directe, comme en production.
        $user->is_blocked = true;
        $user->save();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_admin_report_totals_completed_contributions_in_period(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'beneficiary',
        ]);

        Contribution::create([
            'tontine_member_id' => $member->id, 'round' => 1, 'amount' => 1000, 'commission_amount' => 30,
            'payment_method' => 'card', 'status' => 'completed', 'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.reports.index'));

        $response->assertOk();
        // Les cotisations et les tranches sont rapportées séparément, le total
        // est la somme explicite des deux sources.
        $response->assertViewHas('stats', function ($stats) {
            return $stats['contributions'] == ['count' => 1, 'collected' => 1000.0, 'commission' => 30.0]
                && $stats['installments'] == ['count' => 0, 'collected' => 0.0, 'commission' => 0.0]
                && $stats['totals']['collected'] == 1000.0
                && $stats['totals']['commission'] == 30.0;
        });
    }
}
