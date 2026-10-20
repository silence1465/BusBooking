<?php

namespace App\Services\Payments;

class VerificationResult
{
    public function __construct(
        public readonly string $status, // success | failed | pending
        public readonly int $amountMinor = 0,
        public readonly ?string $currency = null,
        public readonly ?string $channel = null,
        public readonly ?string $gatewayReference = null,
        public readonly array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }
}
