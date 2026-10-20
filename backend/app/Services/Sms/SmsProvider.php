<?php

namespace App\Services\Sms;

interface SmsProvider
{
    public function name(): string;

    /** @return array{ok: bool, response: string} */
    public function send(string $internationalPhone, string $message): array;
}
