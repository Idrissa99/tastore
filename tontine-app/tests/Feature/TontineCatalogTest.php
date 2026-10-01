<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalogue public des tontines.
 *
 * Régression : le catalogue ne filtrait que sur status = "open". Une tontine
 * pleine disparaissait alors de l'onglet alors qu'elle existe toujours et que
 * ses membres la suivent — c'est ce qui donnait l'impression que les tontines
 * créées « n'apparaissaient jamais ».
 */
class TontineCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function tontine(string $status, string $name, string $createdAt): Tontine
    {
        $merchant = User::factory()->create(['role' => 'merchant']);
        $merchant->merchant()->create([
            'business_name' => 'Boutique '.$name, 'status' => 'approved', 'city' => 'Niamey',
        ]);

        $product = Product::factory()->create([
            'merchant_id' => $merchant->merchant->id, 'status' => 'published',
        ]);

        $tontine = Tontine::create([
            'product_id' => $product->id,
            'type' => 'product',
            'created_by' => $merchant->id,
            'name' => $name,
            'total_amount' => 250000,
            'contribution_amount' => 25750,
            'commission_rate' => 0.03,
            'frequency' => 'monthly',
            'max_members' => 10,
            'status' => $status,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $merchant->id,
            'position' => 1,
            'status' => 'active',
        ]);

        // `created_at` est normally géré par Eloquent : on le force pour rendre
        // l'ordre attendu du catalogue explicite dans le test.
        $tontine->timestamps = false;
        $tontine->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $tontine->fresh();
    }

    public function test_the_catalog_lists_every_status(): void
    {
        $this->tontine(Tontine::STATUS_OPEN, 'Ouverte ancienne', '2026-08-01 10:00:00');
        $this->tontine(Tontine::STATUS_OPEN, 'Ouverte recente', '2026-09-20 10:00:00');
        $this->tontine(Tontine::STATUS_ACTIVE, 'Active recente', '2026-09-25 10:00:00');
        $this->tontine(Tontine::STATUS_CANCELLED, 'Annulee recente', '2026-09-26 10:00:00');
        $this->tontine(Tontine::STATUS_COMPLETED, 'Terminee recente', '2026-09-27 10:00:00');

        $response = $this->getJson('/api/tontines')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertCount(5, $names, 'Le catalogue ne doit plus masquer les tontines non ouvertes.');

        foreach (['Ouverte ancienne', 'Ouverte recente', 'Active recente', 'Annulee recente', 'Terminee recente'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_tontines_are_listed_from_most_recent_to_oldest(): void
    {
        $this->tontine(Tontine::STATUS_ACTIVE, 'Active recente', '2026-09-25 10:00:00');
        $this->tontine(Tontine::STATUS_OPEN, 'Ouverte ancienne', '2026-08-01 10:00:00');
        $this->tontine(Tontine::STATUS_CANCELLED, 'Annulee recente', '2026-09-26 10:00:00');
        $this->tontine(Tontine::STATUS_OPEN, 'Ouverte recente', '2026-09-20 10:00:00');

        $names = array_column($this->getJson('/api/tontines')->assertOk()->json('data'), 'name');

        $this->assertSame(
            ['Annulee recente', 'Active recente', 'Ouverte recente', 'Ouverte ancienne'],
            $names,
            'La création trie seule, sans prioriser le statut "open".'
        );
    }

    public function test_available_filter_restricts_to_joinable_tontines(): void
    {
        $this->tontine(Tontine::STATUS_OPEN, 'Ouverte', '2026-09-20 10:00:00');
        $this->tontine(Tontine::STATUS_ACTIVE, 'Active', '2026-09-25 10:00:00');
        $this->tontine(Tontine::STATUS_CANCELLED, 'Annulee', '2026-09-26 10:00:00');

        $names = array_column($this->getJson('/api/tontines?available=1')->assertOk()->json('data'), 'name');

        $this->assertSame(['Ouverte'], $names);
    }

    public function test_joining_a_cancelled_tontine_is_still_refused(): void
    {
        $cancelled = $this->tontine(Tontine::STATUS_CANCELLED, 'Annulee', '2026-09-26 10:00:00');

        // Visible dans le catalogue…
        $this->assertContains('Annulee', array_column($this->getJson('/api/tontines')->json('data'), 'name'));

        // …mais l'adhésion reste impossible.
        $this->actingAs(User::factory()->create())
            ->postJson("/api/tontines/{$cancelled->id}/join")
            ->assertForbidden();
    }

    public function test_the_resource_tells_the_client_whether_joining_is_possible(): void
    {
        $active = $this->tontine(Tontine::STATUS_ACTIVE, 'Active', '2026-09-25 10:00:00');

        $data = $this->getJson("/api/tontines/{$active->id}")->assertOk()->json('data');

        $this->assertSame('active', $data['status']);
        $this->assertFalse($data['is_creator']);
    }
}
