<?php

namespace App\Services\Sms;

/** Dev driver: nothing leaves the machine, the message is only written to sms_logs. */
class LogProvider implements SmsProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $internationalPhone, string $message): array
    {
        return ['ok' => true, 'response' => 'Logged only (SMS_DRIVER=log).'];
    }
}
