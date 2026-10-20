<?php

namespace App\Services\Payments;

interface PaymentGateway
{
    /** @return array{authorization_url: string, access_code: ?string} */
    public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata = []): array;

    public function verify(string $reference): VerificationResult;
}
