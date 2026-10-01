<?php

namespace Tests\Feature\Api;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionApiTest extends TestCase
{
    use RefreshDatabase;

    private function createPendingContribution(): Contribution
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
        ]);
        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'position' => 1,
            'status' => 'active',
        ]);

        return Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 1000,
            'payment_method' => 'mobile_money',
            'status' => 'pending',
        ]);
    }

    public function test_index_includes_the_tontine_excerpt(): void
    {
        // Régression : `whenLoaded('tontineMember.tontine', …)` renvoyait
        // systématiquement faux, et la clé `tontine` disparaissait de la
        // réponse SANS erreur. Le client ne recevait plus ni l'identifiant ni le
        // nom de la tontine : « mes cotisations » perdait son titre, et aucune
        // liste de tontines ne pouvait être déduite de cette ressource.
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $payload = $this->getJson('/api/contributions')
            ->assertOk()
            ->assertJsonStructure([[
                'id',
                'round',
                'amount',
                'status',
                'verification_status',
                'tontine' => ['id', 'name', 'status', 'type'],
            ]]);

        $this->assertSame(
            $contribution->tontineMember->tontine->id,
            $payload->json('0.tontine.id'),
        );
        $this->assertSame(
            $contribution->tontineMember->tontine->name,
            $payload->json('0.tontine.name'),
        );
    }

    public function test_pay_response_includes_the_tontine_excerpt(): void
    {
        // Même exigence sur le retour du paiement : l'écran remplace la carte
        // affichée par cette réponse, il y lit le nom de la tontine.
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertOk()
            ->assertJsonStructure([
                'id',
                'status',
                'tontine' => ['id', 'name', 'status', 'type'],
            ]);
    }

    public function test_transfer_code_response_includes_the_tontine_excerpt(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'mynita',
            'transfer_code' => 'ABC123',
        ])->assertOk()
            ->assertJsonStructure([
                'id',
                'verification_status',
                'tontine' => ['id', 'name', 'status', 'type'],
            ]);
    }

    public function test_manual_channel_cannot_be_paid_directly(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'mynita',
        ])->assertStatus(422);

        $this->assertDatabaseHas('contributions', [
            'id' => $contribution->id,
            'status' => 'pending',
            'verification_status' => 'not_applicable',
        ]);
    }

    public function test_member_can_submit_a_transfer_code_for_a_manual_channel(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $response = $this->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'mynita',
            'transfer_code' => 'TRANSFER-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('verification_status', 'pending')
            ->assertJsonPath('transfer_code', 'TRANSFER-123');

        $this->assertNotNull($response->json('submitted_at'));
        $this->assertDatabaseHas('contributions', [
            'id' => $contribution->id,
            'payment_method' => 'mynita',
            'verification_status' => 'pending',
        ]);
    }

    public function test_transfer_code_requires_a_manual_channel(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'orange_money',
            'transfer_code' => 'TRANSFER-123',
        ])->assertStatus(422);
    }

    public function test_pending_verification_cannot_be_bypassed_with_another_channel(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'amana',
            'transfer_code' => 'TRANSFER-456',
        ])->assertOk();

        $this->postJson("/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertStatus(409);

        $this->assertDatabaseHas('contributions', [
            'id' => $contribution->id,
            'status' => 'pending',
            'verification_status' => 'pending',
        ]);
    }
}
