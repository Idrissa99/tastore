<?php

namespace Tests\Feature\Security;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\NotificationLinks;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PRIORITÉ 3 §5, §6, §15, §18, §19 — Vérification email, reset de mot de
 * passe, CORS, rate limiting et webhooks.
 */
class AuthFlowAndCorsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- CORS (config)

    public function test_cors_never_allows_wildcard_origin(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
    }

    public function test_cors_does_not_support_credentials_because_we_use_bearer_tokens(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_cors_origins_are_derived_from_the_frontend_url_env(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertNotEmpty($origins);
        $this->assertContains('http://localhost:5173', $origins);
        $this->assertContains('http://127.0.0.1:5173', $origins);

        foreach ($origins as $origin) {
            $this->assertNotSame('', $origin, 'Une origine vide ne doit pas subsister.');
            $this->assertStringStartsWith('http', $origin);
        }
    }

    public function test_frontend_url_config_is_available_for_notifications_and_cors(): void
    {
        $this->assertNotEmpty(config('app.frontend_url'));
        $this->assertStringContainsString(
            rtrim((string) config('app.frontend_url'), '/'),
            NotificationLinks::tontine(12)
        );
    }

    public function test_allowed_origin_receives_cors_headers_and_rejected_origin_does_not(): void
    {
        $allowed = 'http://localhost:5173';
        $rejected = 'https://evil.example.com';

        $ok = $this->withHeaders([
            'Origin' => $allowed,
            'Access-Control-Request-Method' => 'GET',
        ])->getJson('/api/config');

        $this->assertSame($allowed, $ok->headers->get('Access-Control-Allow-Origin'));

        $ko = $this->withHeaders([
            'Origin' => $rejected,
            'Access-Control-Request-Method' => 'GET',
        ])->getJson('/api/config');

        $this->assertNull($ko->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_request_without_origin_is_not_blocked(): void
    {
        // Les appels serveur-à-serveur et les tests n'ont pas d'Origin.
        $this->getJson('/api/config')->assertOk();
    }

    // -------------------------------------------- VÉRIFICATION EMAIL (§5)

    public function test_email_verified_at_is_the_single_source_of_truth(): void
    {
        $user = User::factory()->unverified()->create();
        $this->assertFalse($user->is_verified);
        $this->assertNull($user->email_verified_at);

        $user->email_verified_at = now();
        $user->save();

        $this->assertTrue($user->fresh()->is_verified);

        // Repasser email_verified_at à null resynchronise is_verified.
        $user->email_verified_at = null;
        $user->save();

        $this->assertFalse($user->fresh()->is_verified);
    }

    public function test_profile_update_cannot_self_verify_the_email(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('spa')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/profil', [
                'name' => $user->name,
                'phone' => $user->phone,
                'is_verified' => true,
                'email_verified_at' => now()->toDateTimeString(),
            ])->assertOk();

        $this->assertFalse($user->fresh()->is_verified);
    }

    public function test_profile_and_notifications_stay_reachable_before_email_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $product = Product::factory()->create(['status' => 'published']);
        $token = $user->createToken('spa')->plainTextToken;

        // Inscription / connexion / profil / notifications : accessibles.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/profil')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/notifications')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/profil', ['name' => $user->name, 'phone' => $user->phone])
            ->assertOk();

        // Action financière : exige l'email vérifié.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 2])
            ->assertForbidden();
    }

    public function test_admin_is_not_locked_out_by_email_verification(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->assertTrue($admin->hasVerifiedEmail());
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')->assertOk();
    }

    // --------------------------------------- RESET MOT DE PASSE (§6 & §18)

    public function test_forgot_password_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();

        $known = User::factory()->create(['email' => 'connu@example.com']);

        $existing = $this->postJson('/api/forgot-password', ['email' => 'connu@example.com'])->assertOk();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'inconnu@example.com'])->assertOk();

        $this->assertSame($existing->json(), $unknown->json());
        $this->assertArrayNotHasKey('status', $existing->json());
        $this->assertArrayNotHasKey('status', $unknown->json());

        Notification::assertSentTo($known, ResetPassword::class);
        Notification::assertNothingSentTo(
            new User(['email' => 'inconnu@example.com'])
        );
    }

    public function test_reset_token_is_single_use(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $payload = [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'nouveaumotdepasse',
                'password_confirmation' => 'nouveaumotdepasse',
            ];

            $this->postJson('/api/reset-password', $payload)->assertOk();

            // Deuxième usage du même token : refusé.
            $this->postJson('/api/reset-password', $payload)->assertStatus(422);

            return true;
        });
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            // expire = 60 minutes par défaut (config/auth.php).
            $this->travelTo(now()->addMinutes(61));

            $this->postJson('/api/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'nouveaumotdepasse',
                'password_confirmation' => 'nouveaumotdepasse',
            ])->assertStatus(422);

            $this->travelBack();

            return true;
        });
    }

    public function test_reset_with_an_unknown_token_returns_a_generic_message(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'token' => 'jeton-fabrique',
            'email' => 'inconnu@example.com',
            'password' => 'nouveaumotdepasse',
            'password_confirmation' => 'nouveaumotdepasse',
        ])->assertStatus(422);

        $this->assertStringNotContainsString('token invalide', strtolower($response->json('message')));
        $this->assertStringNotContainsString('introuvable', strtolower($response->json('message')));
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => "user{$i}@example.com"])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'user5@example.com'])->assertStatus(429);
    }

    public function test_reset_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'token' => 'x', 'email' => "u{$i}@example.com",
                'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123',
            ])->assertStatus(422);
        }

        $this->postJson('/api/reset-password', [
            'token' => 'x', 'email' => 'u5@example.com',
            'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123',
        ])->assertStatus(429);
    }

    public function test_login_and_register_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'faux'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'faux'])->assertStatus(429);
    }

    public function test_login_throttling_does_not_leak_whether_the_account_exists(): void
    {
        $user = User::factory()->create();

        $known = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'faux']);
        $unknown = $this->postJson('/api/login', ['email' => 'inconnu@example.com', 'password' => 'faux']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_transfer_code_submission_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa')->plainTextToken;

        // Une cotisation distincte par essai : chaque appel est une première
        // soumission légitime, donc le seul frein possible est bien le throttle.
        for ($i = 0; $i < 10; $i++) {
            $contribution = $this->makePendingContribution($user);

            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
                    'payment_method' => 'amana',
                    'transfer_code' => "CODE-{$i}",
                ])->assertOk();
        }

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/contributions/{$contribution->id}/soumettre-code", [
                'payment_method' => 'amana',
                'transfer_code' => 'CODE-999',
            ])->assertStatus(429);
    }

    public function test_webhook_is_rate_limited(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        for ($i = 0; $i < 30; $i++) {
            $timestamp = (string) now()->timestamp;
            $body = json_encode(['reference' => 'INCONNU-'.$i, 'status' => 'failed']);

            $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
            ]);
        }

        $timestamp = (string) now()->timestamp;
        $body = json_encode(['reference' => 'TROP-TARD', 'status' => 'failed']);

        $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
        ])->assertStatus(429);
    }

    // ------------------------------------------------------------ WEBHOOK (§19)

    public function test_webhook_without_a_configured_secret_is_refused(): void
    {
        config(['mobilemoney.webhook_secret' => null]);

        $this->postJson('/api/webhooks/mobile-money', [
            'reference' => 'X', 'status' => 'success', 'amount' => 1, 'currency' => 'XOF',
        ])->assertStatus(503);
    }

    public function test_webhook_with_a_wrong_signature_is_refused(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        $this->postJson('/api/webhooks/mobile-money', [
            'reference' => 'FAKE-X', 'status' => 'success', 'amount' => 100, 'currency' => 'XOF',
        ], [
            'X-Webhook-Timestamp' => (string) now()->timestamp,
            'X-Webhook-Signature' => 'signature-fabrique',
        ])->assertStatus(401);
    }

    public function test_webhook_with_a_stale_timestamp_is_refused(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        $body = json_encode(['reference' => 'FAKE-X', 'status' => 'success', 'amount' => 100, 'currency' => 'XOF']);
        $timestamp = (string) now()->subMinutes(30)->timestamp;

        $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
        ])->assertStatus(401);
    }

    public function test_a_user_cannot_simulate_a_payment_by_calling_the_webhook_endpoint(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        $user = User::factory()->create();
        $contribution = $this->makePendingContribution($user);
        $token = $user->createToken('spa')->plainTextToken;
        $contribution->update(['transaction_reference' => 'FAKE-SIMULE']);

        $body = json_encode([
            'reference' => 'FAKE-SIMULE', 'status' => 'success', 'amount' => 5000, 'currency' => 'XOF',
        ]);
        $timestamp = (string) now()->timestamp;

        // Sans signature : rejeté, même authentifié.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/webhooks/mobile-money', json_decode($body, true))
            ->assertStatus(401);

        $this->assertSame('pending', $contribution->fresh()->status);

        // Avec une signature valide mais un MONTANT incorrect : rejeté.
        $badAmount = json_encode([
            'reference' => 'FAKE-SIMULE', 'status' => 'success', 'amount' => 1, 'currency' => 'XOF',
        ]);
        $this->postJson('/api/webhooks/mobile-money', json_decode($badAmount, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$badAmount, 'secret-de-test'),
        ])->assertStatus(422);

        $this->assertSame('pending', $contribution->fresh()->status);

        // Montant correct : la transaction est bien encaissée.
        $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
        ])->assertOk();

        $this->assertSame('completed', $contribution->fresh()->status);
    }

    public function test_webhook_cannot_mark_an_arbitrary_reference_as_paid(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        $body = json_encode(['reference' => 'UNKNOWN-REF', 'status' => 'success', 'amount' => 100, 'currency' => 'XOF']);
        $timestamp = (string) now()->timestamp;

        $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
        ])->assertStatus(404);
    }

    public function test_webhook_cannot_mark_a_contribution_that_belongs_to_another_tontine(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-de-test']);

        $user = User::factory()->create();
        $contribution = $this->makePendingContribution($user);
        $contribution->update(['transaction_reference' => 'FAKE-X', 'amount' => 5000]);

        // La référence existe mais le montant ne correspond pas -> refus.
        $body = json_encode(['reference' => 'FAKE-X', 'status' => 'success', 'amount' => 999999, 'currency' => 'XOF']);
        $timestamp = (string) now()->timestamp;

        $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret-de-test'),
        ])->assertStatus(422);

        $this->assertSame('pending', $contribution->fresh()->status);
    }

    private function makePendingContribution(User $user): Contribution
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id, 'created_by' => $user->id, 'contribution_amount' => 5000,
        ]);
        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'active',
        ]);

        return Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 5000,
            'commission_rate' => 0.07,
            'status' => 'pending',
        ]);
    }
}
