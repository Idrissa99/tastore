<?php

namespace App\Services\Payments;

final class PaymentResult
{
    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $externalReference,
        public readonly string $status,
        public readonly ?string $paymentUrl = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $eventId = null,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function isFailure(): bool
    {
        return in_array($this->status, [self::FAILED, self::CANCELLED], true);
    }
}
