<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Notifications\TontineUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Modification d'une tontine AVANT son démarrage.
 *
 * Règle centrale : `open` = modifiable, `active` = figée. Le passage à
 * "active" se produit quand la tontine atteint son nombre maximal de membres
 * (TontineService::activateIfFull), c'est-à-dire au moment précis où elle
 * commence à demander de l'argent.
 */
class TontineUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(array $attributes = []): User
    {
        $merchant = User::factory()->create(['role' => 'merchant'] + $attributes);
        $merchant->merchant()->create([
            'business_name' => 'Boutique Test',
            'status' => 'approved',
            'city' => 'Niamey',
        ]);

        return $merchant->fresh();
    }

    private function product(User $owner, string $price = '250000.00'): Product
    {
        return Product::factory()->create([
            'merchant_id' => $owner->merchant?->id ?? $owner->id,
            'price' => $price,
            'status' => 'published',
        ]);
    }

    private function openTontine(User $creator, ?Product $product = null, int $maxMembers = 10): Tontine
    {
        $product ??= $this->product($creator);
        $commissionRate = 0.03;

        $tontine = Tontine::create([
            'product_id' => $product->id,
            'type' => 'product',
            'created_by' => $creator->id,
            'name' => 'Tontine iPhone',
            'total_amount' => $product->price,
            // Même formule que la création : un montant divergent ferait
            // apparaître un « changement » au premier PUT sans raison.
            'contribution_amount' => round(((float) $product->price / $maxMembers) * (1 + $commissionRate), 2),
            'commission_rate' => $commissionRate,
            'frequency' => 'monthly',
            'max_members' => $maxMembers,
            'status' => 'open',
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => 'active',
        ]);

        return $tontine;
    }

    public function test_creator_can_edit_a_tontine_that_has_not_started(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", [
                'name' => 'Tontine iPhone 15 Pro',
                'frequency' => 'weekly',
                'max_members' => 20,
                'start_date' => '2026-12-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tontine iPhone 15 Pro')
            ->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.max_members', 20);

        $this->assertDatabaseHas('tontines', [
            'id' => $tontine->id,
            'name' => 'Tontine iPhone 15 Pro',
            'frequency' => 'weekly',
            'max_members' => 20,
        ]);
    }

    public function test_changing_the_number_of_members_recalculates_the_contribution(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant, $this->product($merchant, '250000.00'));

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['max_members' => 20])
            ->assertOk();

        // (250000 / 20) * 1.03 = 12 875
        $this->assertEqualsWithDelta(12875.00, $tontine->fresh()->contribution_amount, 0.01);
    }

    public function test_editing_is_impossible_once_the_tontine_has_started(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $tontine->update(['status' => Tontine::STATUS_ACTIVE, 'current_round' => 1]);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['name' => 'Tontine renommee'])
            ->assertForbidden();

        $this->assertSame('Tontine iPhone', $tontine->fresh()->name);
    }

    public function test_a_full_tontine_activates_and_becomes_immediately_ineditable(): void
    {
        $merchant = $this->merchant();
        // 2 places : le créateur + un arrivant suffisent à déclencher le
        // démarrage, exactement comme en production.
        $tontine = $this->openTontine($merchant, null, 2);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/tontines/{$tontine->id}/join")
            ->assertOk();

        $this->assertSame(Tontine::STATUS_ACTIVE, $tontine->fresh()->status);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['name' => 'Renommee'])
            ->assertForbidden();
    }

    public function test_a_member_cannot_edit_someone_elses_tontine(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $stranger = User::factory()->create(['role' => 'merchant']);
        $stranger->merchant()->create([
            'business_name' => 'Autre Boutique', 'status' => 'approved', 'city' => 'Zinder',
        ]);

        $this->actingAs($stranger->fresh())
            ->putJson("/api/tontines/{$tontine->id}", ['name' => 'Detournement'])
            ->assertForbidden();

        $this->assertSame('Tontine iPhone', $tontine->fresh()->name);
    }

    public function test_max_members_cannot_drop_below_the_joined_members(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        foreach (range(1, 4) as $position) {
            TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => User::factory()->create()->id,
                'position' => $position + 1,
                'status' => 'active',
            ]);
        }

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['max_members' => 2])
            ->assertStatus(422);

        $this->assertSame(10, (int) $tontine->fresh()->max_members);
    }

    public function test_creator_cannot_switch_to_a_cash_tontine(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", [
                'type' => 'cash',
                'product_id' => null,
                'total_amount' => '500000',
            ])
            ->assertForbidden();
    }

    public function test_creator_cannot_retarget_someone_elses_product(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $other = $this->merchant();
        $foreignProduct = $this->product($other, '999999.00');

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['product_id' => $foreignProduct->id])
            ->assertForbidden();

        $this->assertNotSame((int) $foreignProduct->id, (int) $tontine->fresh()->product_id);
    }

    public function test_members_are_notified_of_the_change(): void
    {
        Notification::fake();

        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);
        $member = User::factory()->create();

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $member->id,
            'position' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['max_members' => 20])
            ->assertOk();

        Notification::assertSentTo($member, TontineUpdatedNotification::class);
        // L'auteur de la modification n'est pas prévenu de sa propre action.
        Notification::assertNotSentTo($merchant, TontineUpdatedNotification::class);
    }

    public function test_no_notification_when_nothing_actually_changed(): void
    {
        Notification::fake();

        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);
        $member = User::factory()->create();

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $member->id,
            'position' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", [
                'name' => 'Tontine iPhone',
                'max_members' => 10,
            ])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_resource_tells_the_creator_why_editing_is_locked(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $this->actingAs($merchant)
            ->getJson("/api/tontines/{$tontine->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.edit_locked_reason', null);

        $tontine->update(['status' => Tontine::STATUS_ACTIVE]);

        $this->actingAs($merchant)
            ->getJson("/api/tontines/{$tontine->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.is_creator', true);
    }

    public function test_a_visitor_cannot_edit(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $this->putJson("/api/tontines/{$tontine->id}", ['name' => 'Anonime'])->assertUnauthorized();
    }

    public function test_validation_rejects_an_invalid_frequency(): void
    {
        $merchant = $this->merchant();
        $tontine = $this->openTontine($merchant);

        $this->actingAs($merchant)
            ->putJson("/api/tontines/{$tontine->id}", ['frequency' => 'yearly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('frequency');
    }
}
