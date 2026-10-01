<?php

namespace Tests\Feature\Security;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_after_too_many_attempts(): void
    {
        $user = User::factory()->create(['email' => 'ratelimit@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertStatus(429);
    }

    public function test_unverified_user_cannot_create_a_tontine(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('tontines.create'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_user_cannot_join_a_tontine(): void
    {
        $user = User::factory()->unverified()->create();
        $product = Product::factory()->create();
        $creator = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $creator->id]);

        $this->actingAs($user)
            ->post(route('tontines.join', $tontine))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_can_access_tontine_creation(): void
    {
        $merchant = \App\Models\Merchant::factory()->create(['status' => 'approved']);

        $this->actingAs($merchant->user) // vérifié par défaut, et autorisé à créer une tontine
            ->get(route('tontines.create'))
            ->assertOk();
    }

    public function test_forgot_password_sends_a_reset_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_webhook_is_idempotent_and_ignores_duplicate_calls(): void
    {
        config(['mobilemoney.webhook_secret' => 'test-secret']);

        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'position' => 1,
            'status' => 'beneficiary',
        ]);

        $contribution = Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 1000,
            'payment_method' => 'mobile_money',
            'status' => 'pending',
            'transaction_reference' => 'FAKE-DUP',
        ]);

        $payload = [
            'reference' => 'FAKE-DUP',
            'status' => 'success',
            'amount' => 1000,
            'currency' => 'XOF',
        ];
        $timestamp = (string) now()->timestamp;
        $headers = [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac(
                'sha256',
                $timestamp . '.' . json_encode($payload),
                (string) config('mobilemoney.webhook_secret'),
            ),
        ];

        // premier appel : traite normalement
        $this->postJson('/api/webhooks/mobile-money', $payload, $headers)->assertOk();
        $firstPaidAt = $contribution->fresh()->paid_at;

        // deuxième appel (rejeu) : ne doit rien changer
        $this->postJson('/api/webhooks/mobile-money', $payload, $headers)->assertOk();

        $this->assertSame('completed', $contribution->fresh()->status);
        $this->assertEquals($firstPaidAt, $contribution->fresh()->paid_at);
    }
}
