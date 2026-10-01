<?php

namespace App\Services\Payments;

final class PaymentIntent
{
    public function __construct(
        public readonly string $paymentKey,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $description = '',
        public readonly array $metadata = [],
    ) {}
}
