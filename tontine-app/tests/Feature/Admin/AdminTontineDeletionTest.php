<?php

namespace Tests\Feature\Admin;

use App\Models\Contribution;
use App\Models\Dispute;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRIORITÉ 4 — Suppression administrative d'une tontine.
 *
 * L'annulation existe déjà et ne détruit rien. La suppression, elle, emporte
 * TOUT en cascade : il n'existe pas un seul RESTRICT ni un seul SET NULL sur
 * les clés étrangères qui pointent vers `tontines.id`. La base ne refusera donc
 * jamais rien, et n'émettra aucun avertissement.
 *
 * Ces tests prouvent deux choses :
 *  1. ce qui n'a jamais touché d'argent se supprime proprement, en cascade ;
 *  2. ce qui porte une trace comptable ou un litige est REFUSÉ, et rien n'est
 *     détruit au passage.
 */
class AdminTontineDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Tontine ouverte, sans argent, avec un membre (le créateur). */
    private function cleanTontine(): Tontine
    {
        $tontine = Tontine::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'status' => Tontine::STATUS_OPEN,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => TontineMember::STATUS_ACTIVE,
        ]);

        return $tontine;
    }

    public function test_a_tontine_that_never_took_money_can_be_deleted(): void
    {
        $tontine = $this->cleanTontine();

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tontines', ['id' => $tontine->id]);
    }

    /** La cascade fait partie du comportement attendu, pas d'un effet de bord subi. */
    public function test_deleting_also_removes_members_and_contributions(): void
    {
        $tontine = $this->cleanTontine();
        $member = $tontine->members()->firstOrFail();

        $contribution = Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_PENDING, // demandée, jamais payée
        ]);

        $this->assertSame([], app(TontineService::class)->deletionBlockers($tontine));

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tontine_members', ['id' => $member->id]);
        $this->assertDatabaseMissing('contributions', ['id' => $contribution->id]);
    }

    public function test_a_tontine_with_a_paid_contribution_cannot_be_deleted(): void
    {
        $tontine = $this->cleanTontine();
        $member = $tontine->members()->firstOrFail();

        $contribution = Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertStatus(409);

        $this->assertStringContainsString('cotisation', $response->json('message'));
        $this->assertStringContainsString('Annule plutôt', $response->json('message'));

        // Le refus ne doit RIEN détruire : le reçu de paiement est intact.
        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
        $this->assertDatabaseHas('contributions', ['id' => $contribution->id]);
    }

    public function test_a_tontine_with_a_dispute_cannot_be_deleted(): void
    {
        $tontine = $this->cleanTontine();

        Dispute::create([
            'tontine_id' => $tontine->id,
            'raised_by' => User::factory()->create()->id,
            'subject' => 'Produit non livré',
            'description' => 'Le commerçant a disparu.',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }

    public function test_a_tontine_with_a_refund_cannot_be_deleted(): void
    {
        $tontine = $this->cleanTontine();
        $member = $tontine->members()->firstOrFail();

        $contribution = Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        // Remboursement déclaré à la main et déjà traité : l'argent est parti.
        Refund::create([
            'contribution_id' => $contribution->id,
            'tontine_id' => $tontine->id,
            'user_id' => $member->user_id,
            'amount' => 10000,
            'method' => Refund::METHOD_MANUAL,
            'status' => Refund::STATUS_PROCESSED,
            'processed_by' => $this->admin()->id,
            'processed_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('refunds', ['tontine_id' => $tontine->id]);
    }

    public function test_a_non_admin_cannot_delete_a_tontine(): void
    {
        $tontine = $this->cleanTontine();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }

    public function test_a_blocked_admin_cannot_delete_a_tontine(): void
    {
        $tontine = $this->cleanTontine();

        $admin = $this->admin();
        $admin->is_blocked = true; // pas fillable : affectation directe
        $admin->save();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/tontines/{$tontine->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }

    /**
     * La même garantie doit tenir sur la surface Blade, y compris pour un
     * administrateur suspendu : le groupe /admin web ne portait pas `active`
     * alors que tous les autres groupes web le portent.
     */
    public function test_the_blade_surface_applies_the_same_rules(): void
    {
        $tontine = $this->cleanTontine();

        $this->actingAs($this->admin())
            ->delete(route('admin.tontines.destroy', $tontine))
            ->assertRedirect();

        $this->assertDatabaseMissing('tontines', ['id' => $tontine->id]);
    }

    public function test_the_blade_surface_refuses_a_tontine_with_money(): void
    {
        $tontine = $this->cleanTontine();
        $member = $tontine->members()->firstOrFail();

        Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.tontines.destroy', $tontine))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }

    public function test_a_blocked_admin_cannot_delete_through_the_blade_surface(): void
    {
        $tontine = $this->cleanTontine();

        $admin = $this->admin();
        $admin->is_blocked = true;
        $admin->save();

        $this->actingAs($admin)
            ->delete(route('admin.tontines.destroy', $tontine))
            ->assertForbidden();

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }

    /**
     * Le contrôle est rejoué SOUS VERROU dans le service, pas seulement lu
     * avant : c'est ce qui empêche un paiement encaissé entre la vérification et
     * le DELETE d'être emporté en silence. Appeler deux fois de suite ne doit
     * rien laisser derrière.
     */
    public function test_the_service_refuses_to_delete_and_keeps_everything(): void
    {
        $tontine = $this->cleanTontine();
        $member = $tontine->members()->firstOrFail();

        Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        $service = app(TontineService::class);
        $blockers = $service->deletionBlockers($tontine);

        $this->assertNotSame([], $blockers);
        $this->assertStringContainsString('cotisation', $service->deletionRefusalMessage($blockers));

        $this->assertDatabaseHas('tontines', ['id' => $tontine->id]);
    }
}
