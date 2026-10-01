<?php

namespace App\Services\Payments;

interface PaymentGatewayInterface
{
    public function provider(): string;

    public function isTestGateway(): bool;

    public function initiate(PaymentIntent $intent): PaymentResult;

    public function verify(string $externalReference): PaymentResult;

    public function handleWebhook(WebhookRequest $request): PaymentResult;
}
