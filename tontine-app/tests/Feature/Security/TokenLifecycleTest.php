<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * PRIORITÉ 3 §2 & §3 — Expiration et révocation des tokens Sanctum.
 */
class TokenLifecycleTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    private function issueToken(User $user): string
    {
        return $this->issueTokenFor($user);
    }

    public function test_sanctum_expiration_is_configured_and_not_disabled(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull($expiration, 'SANCTUM_TOKEN_EXPIRATION doit être défini.');
        $this->assertGreaterThan(0, $expiration);
        $this->assertLessThanOrEqual(60 * 24 * 60, $expiration, 'Une durée supérieure à 60 jours est unreasonable.');
    }

    public function test_a_token_longer_than_the_configured_expiration_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);

        // Juste avant l'échéance : accepté.
        $expiration = (int) config('sanctum.expiration');
        $this->travelTo(now()->addMinutes($expiration - 5));

        $this->getAs($token, '/api/me')->assertOk();

        // Au-delà : refusé, et le client doit être forcé de se reconnecter.
        $this->travelTo(now()->addMinutes(10));

        $this->getAs($token, '/api/me')->assertStatus(401);

        $this->travelBack();
    }

    public function test_logout_revokes_the_token_used(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $this->postAs($token, '/api/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        // L'ancien token ne donne plus accès à rien.
        $this->getAs($token, '/api/me')->assertStatus(401);
    }

    public function test_logout_does_not_revoke_other_sessions(): void
    {
        $user = User::factory()->create();
        $phone = $this->issueToken($user);
        $tablet = $this->issueToken($user);

        $this->postAs($phone, '/api/logout')->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->getAs($tablet, '/api/me')->assertOk();
    }

    public function test_changing_the_password_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien-mot-de-passe')]);
        $stolen = $this->issueToken($user);
        $current = $this->issueToken($user);

        $this->putAs($current, '/api/profil', [
            'name' => $user->name,
            'phone' => $user->phone,
            'current_password' => 'ancien-mot-de-passe',
            'new_password' => 'nouveau-mot-de-passe',
            'new_password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertOk();

        // Le jeton courant reste valide : l'utilisateur n'est pas déconnecté.
        $this->getAs($current, '/api/me')->assertOk();

        // Le jeton volé est mort.
        $this->getAs($stolen, '/api/me')->assertStatus(401);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_old_token_is_dead_after_a_password_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('ancien-mot-de-passe')]);
        $old = $this->issueToken($user);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->postJson('/api/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'mot-de-passe-neuf',
                'password_confirmation' => 'mot-de-passe-neuf',
            ])->assertOk();

            return true;
        });

        $this->assertSame(0, $user->tokens()->count());

        // Même la session ayant déclenché le reset est invalidée : l'utilisateur
        // doit se reconnecter avec le nouveau mot de passe.
        $this->getAs($old, '/api/me')->assertStatus(401);
    }

    public function test_old_tokens_are_dead_after_an_account_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $victim = User::factory()->create();
        $token = $this->issueToken($victim);

        $this->getAs($token, '/api/me')->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$victim->id}/block")
            ->assertOk()
            ->assertJsonPath('revoked_sessions', 1);

        // Le token est révoqué en base...
        $this->assertSame(0, $victim->fresh()->tokens()->count());

        // ...et le middleware `active` refuse aussi l'accès.
        $this->getAs($token, '/api/me')->assertStatus(401);
    }

    public function test_a_blocked_user_cannot_reuse_its_token_after_being_unblocked(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$user->id}/block")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$user->id}/unblock")->assertOk();

        // Le déblocage ne ressuscite PAS l'ancien jeton.
        $this->getAs($token, '/api/me')->assertStatus(401);
    }

    public function test_blocked_user_cannot_log_in_again(): void
    {
        $user = User::factory()->create(['password' => Hash::make('motdepasse')]);
        $user->is_blocked = true;
        $user->save();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'motdepasse',
        ]);

        $response->assertStatus(403);
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_sessions_endpoint_lists_tokens_without_exposing_their_value(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $response = $this->getAs($token, '/api/sessions')->assertOk()->json();

        $this->assertCount(1, $response);
        $this->assertArrayHasKey('id', $response[0]);
        $this->assertArrayHasKey('name', $response[0]);
        $this->assertTrue($response[0]['is_current']);

        $body = json_encode($response);
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('token', strtolower($body));
    }

    public function test_revoke_all_and_revoke_others_helpers(): void
    {
        $user = User::factory()->create();
        $a = $user->createToken('a')->accessToken;
        $user->createToken('b');
        $user->createToken('c');

        $service = app(SessionRevocationService::class);

        $this->assertSame(2, $service->revokeOthers($user, $a));
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->assertSame(1, $service->revokeAll($user->fresh()));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_revoke_current_token_is_safe_without_a_personal_access_token(): void
    {
        $user = User::factory()->create();

        // Garde `web` : aucun token courant -> aucun crash, retour false.
        $this->assertFalse(app(SessionRevocationService::class)->revokeCurrentToken($user));
    }

    public function test_expired_tokens_are_rejected_even_when_the_row_still_exists(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $this->travelTo(now()->addMinutes(120));

        $this->getAs($token, '/api/me')->assertStatus(401);

        $this->travelBack();

        $this->assertSame(1, PersonalAccessToken::count());
    }
}
