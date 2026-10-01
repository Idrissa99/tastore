<?php

namespace Tests\Feature\Api;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobilemoney.webhook_secret' => 'test-secret']);
    }

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
            'status' => 'beneficiary',
        ]);

        return Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => $tontine->contribution_amount,
            'payment_method' => 'mobile_money',
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_user_can_initiate_a_payment(): void
    {
        $contribution = $this->createPendingContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $response = $this->postJson("/api/contributions/{$contribution->id}/initiate-payment");

        $response->assertOk()->assertJsonStructure(['reference', 'payment_url']);

        $this->assertNotNull($contribution->fresh()->transaction_reference);
    }

    public function test_webhook_without_valid_signature_is_rejected(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-123']);

        $response = $this->postJson('/api/webhooks/mobile-money', [
            'reference' => 'FAKE-123',
            'status' => 'success',
        ]); // pas de header de signature

        $response->assertStatus(401);
        $this->assertSame('pending', $contribution->fresh()->status);
    }

    public function test_webhook_rejection_is_journalised_for_the_operator(): void
    {
        // PRIORITÉ 4 §23 : un opérateur dont les webhooks sont rejetés ne voit
        // que des 401/503. Sans journalisation, impossible de savoir si le
        // problème vient du secret, de l'horloge ou du format.
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-123']);

        $warnings = [];

        Log::listen(function (MessageLogged $message) use (&$warnings) {
            if ($message->level === 'warning') {
                $warnings[] = ['message' => $message->message, 'context' => $message->context];
            }
        });

        $this->postJson('/api/webhooks/mobile-money', [
            'reference' => 'FAKE-123',
            'status' => 'success',
        ])->assertStatus(401);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Webhook', $warnings[0]['message']);
        // Sans en-tête de timestamp, c'est la fenêtre anti-rejeu qui tranche
        // en premier : le motif journalisé doit être celui réellement rencontré.
        $this->assertSame('Timestamp webhook invalide.', $warnings[0]['context']['reason']);
        $this->assertSame(401, $warnings[0]['context']['status']);
    }

    public function test_webhook_signature_rejection_is_journalised(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-789']);

        $reasons = [];

        Log::listen(function (MessageLogged $message) use (&$reasons) {
            if ($message->level === 'warning') {
                $reasons[] = $message->context['reason'] ?? null;
            }
        });

        $payload = ['reference' => 'FAKE-789', 'status' => 'success'];
        $timestamp = (string) now()->timestamp;
        $body = json_encode($payload);

        // Horodatage valide, donc c'est bien la SIGNATURE qui est rejetée :
        // c'est le cas le plus fréquent en production (secret divergent).
        $this->postJson('/api/webhooks/mobile-money', $payload, [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => 'signature-forgee',
        ])->assertStatus(401);

        $this->assertContains('Signature webhook invalide.', $reasons);
    }

    public function test_webhook_log_never_contains_the_payload(): void
    {
        // Le journal ne doit pas devenir une fuite : ni code de transfert, ni
        // montant, ni corps de requête.
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-123']);

        $logged = [];

        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode($message->context);
        });

        $this->postJson('/api/webhooks/mobile-money', [
            'reference' => 'FAKE-123',
            'status' => 'success',
            'amount' => 999999,
        ])->assertStatus(401);

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('999999', implode("\n", $logged));
    }

    public function test_webhook_with_valid_signature_completes_the_contribution(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-456']);

        $payload = [
            'reference' => 'FAKE-456',
            'status' => 'success',
            'amount' => (float) $contribution->amount,
            'currency' => 'XOF',
        ];

        $response = $this->postJson('/api/webhooks/mobile-money', $payload, $this->webhookHeaders($payload));

        $response->assertOk();
        $this->assertSame('completed', $contribution->fresh()->status);
        $this->assertNotNull($contribution->fresh()->paid_at);
    }

    public function test_webhook_failure_marks_the_contribution_as_failed(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-FAILED']);

        $payload = [
            'reference' => 'FAKE-FAILED',
            'status' => 'failed',
            'reason' => 'Provider declined',
        ];

        $this->postJson('/api/webhooks/mobile-money', $payload, $this->webhookHeaders($payload))
            ->assertOk();

        $this->assertSame('failed', $contribution->fresh()->status);
        $this->assertSame('Provider declined', $contribution->fresh()->payment_failure_reason);
    }

    public function test_webhook_rejects_an_amount_mismatch(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-AMOUNT']);

        $payload = [
            'reference' => 'FAKE-AMOUNT',
            'status' => 'success',
            'amount' => (float) $contribution->amount + 1,
            'currency' => 'XOF',
        ];

        $this->postJson('/api/webhooks/mobile-money', $payload, $this->webhookHeaders($payload))
            ->assertStatus(422);

        $this->assertSame('pending', $contribution->fresh()->status);
    }

    public function test_webhook_rejects_a_currency_mismatch(): void
    {
        $contribution = $this->createPendingContribution();
        $contribution->update(['transaction_reference' => 'FAKE-CURRENCY']);

        $payload = [
            'reference' => 'FAKE-CURRENCY',
            'status' => 'success',
            'amount' => (float) $contribution->amount,
            'currency' => 'EUR',
        ];

        $this->postJson('/api/webhooks/mobile-money', $payload, $this->webhookHeaders($payload))
            ->assertStatus(422);

        $this->assertSame('pending', $contribution->fresh()->status);
    }

    public function test_webhook_with_unknown_reference_returns_404(): void
    {
        $payload = [
            'reference' => 'DOES-NOT-EXIST',
            'status' => 'success',
            'amount' => 1000,
            'currency' => 'XOF',
        ];

        $this->postJson('/api/webhooks/mobile-money', $payload, $this->webhookHeaders($payload))
            ->assertStatus(404);
    }

    private function webhookHeaders(array $payload): array
    {
        $timestamp = (string) now()->timestamp;
        $body = json_encode($payload);

        return [
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, (string) config('mobilemoney.webhook_secret')),
        ];
    }
}
