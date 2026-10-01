<?php

namespace Tests\Feature\Admin;

use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_can_approve_a_pending_merchant(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);

        $this->actingAs($this->admin())
            ->post(route('admin.merchants.approve', $merchant))
            ->assertRedirect();

        $this->assertSame('approved', $merchant->fresh()->status);
    }

    public function test_non_admin_cannot_approve_a_merchant(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);
        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('admin.merchants.approve', $merchant))
            ->assertForbidden();

        $this->assertSame('pending', $merchant->fresh()->status);
    }

    public function test_admin_can_cancel_an_active_tontine(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $creator->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.tontines.cancel', $tontine))
            ->assertRedirect();

        $this->assertSame('cancelled', $tontine->fresh()->status);
    }

    public function test_admin_cannot_cancel_a_completed_tontine(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $creator->id,
            'status' => 'completed',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.tontines.cancel', $tontine))
            ->assertStatus(409);
    }

    public function test_member_can_raise_a_dispute_and_admin_can_resolve_it(): void
    {
        $product = Product::factory()->create();
        $member = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $member->id]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $member->id,
            'position' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($member)->post(route('disputes.store', $tontine), [
            'subject' => 'Cotisation non prise en compte',
            'description' => 'J\'ai payé mais le statut est resté pending.',
        ])->assertRedirect();

        $dispute = Dispute::first();
        $this->assertNotNull($dispute);
        $this->assertSame('open', $dispute->status);

        $this->actingAs($this->admin())
            ->post(route('admin.disputes.resolve', $dispute), [
                'status' => 'resolved',
                'resolution_note' => 'Vérifié et corrigé manuellement.',
            ])->assertRedirect();

        $this->assertSame('resolved', $dispute->fresh()->status);
        $this->assertNotNull($dispute->fresh()->resolved_at);
    }

    public function test_non_member_cannot_raise_a_dispute_on_a_tontine(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $creator->id]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('disputes.store', $tontine), [
            'subject' => 'Test',
            'description' => 'Test',
        ])->assertForbidden();
    }
}
