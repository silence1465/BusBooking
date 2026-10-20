<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Local development / test gateway. Never enabled in production (see AppServiceProvider).
 * The "checkout page" is the frontend callback itself and every payment verifies as successful.
 */
class FakeGateway implements PaymentGateway
{
    public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
    {
        return [
            'authorization_url' => $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').'reference='.urlencode($reference).'&mock=1',
            'access_code' => 'fake',
        ];
    }

    public function verify(string $reference): VerificationResult
    {
        $payment = Payment::where('reference', $reference)->first();
        if (! $payment) {
            return new VerificationResult('failed');
        }

        return new VerificationResult(
            'success',
            (int) round($payment->amount * 100),
            $payment->currency,
            'fake',
            'FAKE-'.$reference,
        );
    }
}
