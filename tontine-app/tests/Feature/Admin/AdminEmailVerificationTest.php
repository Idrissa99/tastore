<?php

namespace Tests\Feature\Admin;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Notifications\EmailVerifiedByAdminNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vérification manuelle d'e-mail par un administrateur.
 *
 * L'enjeu métier : `email_verified_at` est la SEULE source de vérité du
 * middleware `verified`, qui interdit à un client/commerçant de rejoindre
 * une tontine, d'en créer une et de payer. Sur le terrain, beaucoup de
 * membres ne reçoivent jamais le lien : l'administrateur doit pouvoir
 * valider l'adresse après contrôle, puis revenir en arrière.
 */
class AdminEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function client(bool $verified = false): User
    {
        return User::factory()->create([
            'role' => 'client',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function merchant(bool $verified = false): User
    {
        return User::factory()->create([
            'role' => 'merchant',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Autorisation */
    /* ---------------------------------------------------------------- */

    public function test_guest_cannot_verify_anyone(): void
    {
        $user = $this->client();

        $this->postJson("/api/admin/users/{$user->id}/verify-email")->assertUnauthorized();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_client_cannot_verify_an_account(): void
    {
        $attacker = $this->client();
        $victim = $this->client();
        Sanctum::actingAs($attacker);

        $this->postJson("/api/admin/users/{$victim->id}/verify-email")->assertForbidden();

        $this->assertNull($victim->fresh()->email_verified_at);
    }

    public function test_a_merchant_cannot_verify_an_account(): void
    {
        $merchant = $this->merchant();
        $victim = $this->client();
        Sanctum::actingAs($merchant);

        $this->postJson("/api/admin/users/{$victim->id}/verify-email")->assertForbidden();

        $this->assertNull($victim->fresh()->email_verified_at);
    }

    /* ---------------------------------------------------------------- */
    /* Comportement nominal */
    /* ---------------------------------------------------------------- */

    public function test_admin_can_verify_an_unverified_client(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = $this->client();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/users/{$user->id}/verify-email")
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'is_verified', 'email_verified_at']]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->email_verified_at);
        // `is_verified` est dérivé de `email_verified_at` par User::booted() :
        // les deux doivent rester cohérents.
        $this->assertTrue($fresh->is_verified);
        $this->assertTrue($fresh->hasVerifiedEmail());
        $this->assertTrue($response->json('user.is_verified'));

        // Le membre doit être prévenu, sinon il reste bloqué sans comprendre.
        Notification::assertSentTo($user, EmailVerifiedByAdminNotification::class);
    }

    public function test_admin_can_verify_an_unverified_merchant(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $merchant = $this->merchant();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$merchant->id}/verify-email")->assertOk();

        $this->assertTrue($merchant->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($merchant, EmailVerifiedByAdminNotification::class);
    }

    /**
     * Preuve du bénéfice réel : avant la validation manuelle, le middleware
     * `verified` bloque TOUTE action métier du membre. Après, la même
     * action aboutit.
     *
     * On utilise `POST /produits/{product}/tranches` : son résultat est
     * déterministe (201) quand tout est valide, contrairement à `join`
     * dont le statut dépend de l'état de la tontine.
     */
    public function test_verifying_unblocks_the_verified_middleware(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $user = $this->client();

        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'published',
            'price' => 100000,
            'stock' => 5,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Avant : `verified` refuse l'action métier (403 du middleware).
        $this->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])
            ->assertStatus(403);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/verify-email")->assertOk();

        // `fresh()` est INDISPENSABLE ici : `Sanctum::actingAs()` injecte une
        // instance figée du modèle, or la vérification vient de modifier la
        // colonne. En production ce piège n'existe pas — Sanctum recharge
        // l'utilisateur depuis la base à chaque requête, et le middleware
        // `verified` lit donc toujours un état à jour.
        Sanctum::actingAs($user->fresh(), ['*']);
        $this->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])
            ->assertStatus(201);
    }

    /**
     * Même garantie, mais par le flux RÉEL : un jeton Sanctum et des
     * en-têtes Authorization. C'est la preuve la plus fidèle du
     * comportement en production — aucune instance de modèle injectée.
     */
    public function test_real_sanctum_token_flow_is_unblocked_by_verification(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $user = $this->client();

        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'published',
            'price' => 100000,
            'stock' => 5,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $before = $this->withHeaders($headers)
            ->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])
            ->assertStatus(403);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/verify-email")->assertOk();

        // Le jeton du membre n'a pas changé et n'a pas été révoqué : c'est
        // bien la vérification qui débloque l'action, à requête identique.
        $after = $this->withHeaders($headers)
            ->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])
            ->assertStatus(201);

        $this->assertSame(403, $before->status());
        $this->assertSame(201, $after->status());
    }

    public function test_unverifying_blocks_the_verified_middleware_again(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $user = $this->client(verified: true);

        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'published',
            'price' => 100000,
            'stock' => 5,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])->assertStatus(201);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/unverify-email")->assertOk();

        // La rétrogradation referme l'accès : c'est le but de l'action.
        Sanctum::actingAs($user->fresh(), ['*']);
        $this->postJson("/api/produits/{$product->id}/tranches", ['installments_count' => 3])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------- */
    /* Rétrogradation */
    /* ---------------------------------------------------------------- */

    public function test_admin_can_remove_a_manual_verification(): void
    {
        $admin = $this->admin();
        $user = $this->client(verified: true);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$user->id}/unverify-email")
            ->assertOk()
            ->assertJsonStructure(['message', 'revoked_sessions']);

        $fresh = $user->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertFalse($fresh->is_verified);
        $this->assertFalse($fresh->hasVerifiedEmail());
    }

    public function test_unverify_revokes_existing_sessions(): void
    {
        $admin = $this->admin();
        $user = $this->client(verified: true);
        $user->createToken('existing')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/admin/users/{$user->id}/unverify-email")->assertOk();

        // Retirer un droit doit couper l'accès déjà délivré, sinon le membre
        // reste connecté sur un compte que `verified` ne devrait plus laisser agir.
        $this->assertSame(1, $response->json('revoked_sessions'));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    /* ---------------------------------------------------------------- */
    /* Renvoi du lien */
    /* ---------------------------------------------------------------- */

    public function test_admin_can_resend_the_verification_link(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = $this->client();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$user->id}/resend-verification")
            ->assertOk()
            ->assertJsonStructure(['message']);

        // La preuve doit rester dans le domaine du titulaire de l'adresse :
        // on renvoie le lien officiel, on ne valide rien à sa place.
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous */
    /* ---------------------------------------------------------------- */

    public function test_cannot_verify_twice(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = $this->client(verified: true);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$user->id}/verify-email")->assertStatus(409);

        Notification::assertNothingSent();
    }

    public function test_cannot_unverify_an_unverified_account(): void
    {
        $admin = $this->admin();
        $user = $this->client();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$user->id}/unverify-email")->assertStatus(409);
    }

    public function test_cannot_resend_to_an_already_verified_account(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = $this->client(verified: true);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$user->id}/resend-verification")->assertStatus(409);
        Notification::assertNothingSent();
    }

    public function test_cannot_touch_another_administrator(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $other = $this->admin();
        Sanctum::actingAs($admin);

        // Un admin est TOUJOURS considéré comme vérifié : agir sur lui serait
        // une action sans effet et surtout trompeuse dans l'interface.
        $this->postJson("/api/admin/users/{$other->id}/verify-email")->assertForbidden();
        $this->postJson("/api/admin/users/{$other->id}/unverify-email")->assertForbidden();
        $this->postJson("/api/admin/users/{$other->id}/resend-verification")->assertForbidden();

        Notification::assertNothingSent();
    }

    /* ---------------------------------------------------------------- */
    /* Filtre de la liste */
    /* ---------------------------------------------------------------- */

    public function test_admin_users_can_be_filtered_by_verification(): void
    {
        $admin = $this->admin();
        $verified = $this->client(verified: true);
        $unverified = $this->client();
        Sanctum::actingAs($admin);

        $onlyUnverified = $this->getJson('/api/admin/users?verified=no')->assertOk();
        $ids = collect($onlyUnverified->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unverified->id));
        $this->assertFalse($ids->contains($verified->id));

        $onlyVerified = $this->getJson('/api/admin/users?verified=yes')->assertOk();
        $ids = collect($onlyVerified->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($verified->id));
        $this->assertFalse($ids->contains($unverified->id));
    }

    public function test_verified_filter_combines_with_existing_filters(): void
    {
        $admin = $this->admin();
        $this->client(verified: false);
        $merchant = $this->merchant(verified: false);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users?verified=no&role=merchant')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($merchant->id));
        $this->assertSame(1, $ids->count());
    }

    public function test_email_verified_at_is_not_mass_assignable(): void
    {
        $user = $this->client();

        // La colonne ne doit JAMAIS pouvoir être posée par une requête HTTP :
        // seule la validation manuelle par un administrateur est légitime.
        $this->assertNotContains('email_verified_at', $user->getFillable());
        $this->assertNotContains('is_verified', $user->getFillable());
    }
}
