<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class ArkeselProvider implements SmsProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
    ) {}

    public function name(): string
    {
        return 'arkesel';
    }

    public function send(string $internationalPhone, string $message): array
    {
        if (! $this->apiKey) {
            return ['ok' => false, 'response' => 'ARKESEL_API_KEY is not configured.'];
        }

        $response = Http::withHeaders(['api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(10)
            ->post($this->baseUrl.'/sms/send', [
                'sender' => $this->senderId,
                'message' => $message,
                'recipients' => [$internationalPhone],
            ]);

        return [
            'ok' => $response->successful() && $response->json('status') === 'success',
            'response' => mb_substr($response->body(), 0, 2000),
        ];
    }
}
