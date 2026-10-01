<?php

namespace App\Services\Payments;

final class WebhookRequest
{
    public function __construct(
        public readonly array $payload,
        public readonly ?string $signature,
        public readonly ?string $rawBody,
        public readonly ?string $timestamp,
        public readonly ?string $eventId,
    ) {}
}
