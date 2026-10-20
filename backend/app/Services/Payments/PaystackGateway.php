<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackGateway implements PaymentGateway
{
    public function __construct(private readonly string $secretKey, private readonly string $baseUrl)
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('PAYSTACK_SECRET_KEY is not configured.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->secretKey)->acceptJson()->timeout(20)->baseUrl($this->baseUrl);
    }

    public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
    {
        $response = $this->http()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        if (! $response->successful() || ! $response->json('status')) {
            throw new RuntimeException('Paystack initialize failed: '.($response->json('message') ?? $response->status()));
        }

        return [
            'authorization_url' => $response->json('data.authorization_url'),
            'access_code' => $response->json('data.access_code'),
        ];
    }

    public function verify(string $reference): VerificationResult
    {
        $response = $this->http()->get('/transaction/verify/'.rawurlencode($reference));

        if (! $response->successful()) {
            // Unknown reference / temporary failure: not proof of payment either way.
            return new VerificationResult('pending', raw: $response->json() ?? []);
        }

        $data = $response->json('data') ?? [];
        $status = match ($data['status'] ?? null) {
            'success' => 'success',
            'failed', 'reversed' => 'failed',
            default => 'pending', // abandoned, ongoing, pending...
        };

        return new VerificationResult(
            $status,
            (int) ($data['amount'] ?? 0),
            $data['currency'] ?? null,
            $data['channel'] ?? null,
            isset($data['id']) ? (string) $data['id'] : ($data['reference'] ?? null),
            $data,
        );
    }
}
