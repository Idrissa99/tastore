<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

class FakeMobileMoneyGateway implements PaymentGatewayInterface
{
    public function provider(): string
    {
        return 'fake';
    }

    public function isTestGateway(): bool
    {
        return true;
    }

    public function initiate(PaymentIntent $intent): PaymentResult
    {
        $this->assertAvailable();

        $reference = 'FAKE-'.strtoupper(Str::random(10));
        $status = ($intent->metadata['simulate_success'] ?? false)
            ? PaymentResult::SUCCEEDED
            : PaymentResult::PENDING;

        return new PaymentResult(
            externalReference: $reference,
            status: $status,
            paymentUrl: null,
            amount: $intent->amount,
            currency: $intent->currency,
            raw: ['simulated' => true],
        );
    }

    public function verify(string $externalReference): PaymentResult
    {
        $this->assertAvailable();

        return new PaymentResult(
            externalReference: $externalReference,
            status: PaymentResult::PENDING,
        );
    }

    public function handleWebhook(WebhookRequest $request): PaymentResult
    {
        $this->assertAvailable();
        $this->assertValidSignature($request);

        $reference = $request->payload['reference'] ?? null;

        if (! is_string($reference) || $reference === '' || strlen($reference) > 100) {
            throw new PaymentException('Référence de transaction invalide.');
        }

        $status = match (strtolower((string) ($request->payload['status'] ?? ''))) {
            'success', 'succeeded', 'completed' => PaymentResult::SUCCEEDED,
            'failed', 'failure' => PaymentResult::FAILED,
            'cancelled', 'canceled' => PaymentResult::CANCELLED,
            'pending' => PaymentResult::PENDING,
            default => throw new PaymentException('Statut de paiement invalide.'),
        };

        $amount = array_key_exists('amount', $request->payload) && $request->payload['amount'] !== null
            ? (float) $request->payload['amount']
            : null;
        $currency = isset($request->payload['currency'])
            ? strtoupper((string) $request->payload['currency'])
            : null;

        if ($status === PaymentResult::SUCCEEDED && ($amount === null || $currency === null)) {
            throw new PaymentException('Le montant et la devise sont obligatoires pour confirmer un paiement.');
        }

        return new PaymentResult(
            externalReference: $reference,
            status: $status,
            amount: $amount,
            currency: $currency,
            eventId: $request->eventId ?? ($request->payload['event_id'] ?? null),
            reason: $request->payload['reason'] ?? null,
            raw: $request->payload,
        );
    }

    private function assertAvailable(): void
    {
        if (app()->environment('production') && ! config('payments.allow_fake_in_production', false)) {
            throw new PaymentException('Le gateway factice est désactivé en production. Configurez un fournisseur réel.', 503);
        }
    }

    private function assertValidSignature(WebhookRequest $request): void
    {
        $secret = (string) config('mobilemoney.webhook_secret');

        if ($secret === '' || $secret === 'change-me-in-env') {
            throw new PaymentException('Le secret webhook n\'est pas configuré.', 503);
        }

        $timestamp = (string) $request->timestamp;
        $tolerance = (int) config('payments.webhook_tolerance', 300);

        if ($timestamp === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            throw new PaymentException('Timestamp webhook invalide.', 401);
        }

        $body = $request->rawBody ?? json_encode($request->payload);
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        if (! $request->signature || ! hash_equals($expected, $request->signature)) {
            throw new PaymentException('Signature webhook invalide.', 401);
        }
    }
}
