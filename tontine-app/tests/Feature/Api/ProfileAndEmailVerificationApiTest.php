<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileAndEmailVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le client mobile envoyait `PATCH /api/profil` alors que la route ne
     * déclare que `PUT` : la réponse était 405 et la fiche profil de
     * l'application était inutilisable, quel que soit le contenu envoyé.
     *
     * Ce test verrouille le verbe, et non seulement la route : ajouter `PATCH`
     * côté API « pour faire comme l'app » aurait au contraire laissé deux
     *Endpoints pour la même action.
     */
    public function test_profile_update_accepts_only_the_put_verb(): void
    {
        $user = User::factory()->create(['phone' => '90123456']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profil', [
            'name' => 'Awa Sanou',
            'phone' => '90123456',
        ])->assertOk();

        $this->patchJson('/api/profil', [
            'name' => 'Awa Sanou',
            'phone' => '90123456',
        ])->assertStatus(405);

        $this->assertSame('Awa Sanou', $user->fresh()->name);
    }

    public function test_profile_update_returns_the_fresh_user(): void
    {
        // L'application réinjecte cette réponse dans sa session : sans elle,
        // l'accueil continuerait d'afficher l'ancien nom après enregistrement.
        $user = User::factory()->create(['phone' => '90123456']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profil', [
            'name' => 'Awa Sanou II',
            'phone' => '90123456',
        ])->assertOk();

        $response->assertJsonPath('name', 'Awa Sanou II');
        $response->assertJsonPath('id', $user->id);
    }

    public function test_user_can_upload_an_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['phone' => '90123456']);
        Sanctum::actingAs($user);

        $this->put('/api/profil', [
            'name' => 'Profil avec avatar',
            'phone' => '90123456',
            'avatar' => UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_email_verification_state_is_synchronized(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertFalse($user->fresh()->is_verified);

        $user->markEmailAsVerified();
        $this->assertTrue($user->fresh()->is_verified);

        $user->markEmailAsUnverified();
        $this->assertFalse($user->fresh()->is_verified);
    }
}
