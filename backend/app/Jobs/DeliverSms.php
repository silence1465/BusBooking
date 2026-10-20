<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one queued SMS. It never throws: the outcome (sent or failed) is written to the sms_log row, and an
 * SMS problem must never affect a booking, a payment or a dispatch.
 */
class DeliverSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $logId) {}

    public function handle(SmsService $sms): void
    {
        $log = SmsLog::find($this->logId);

        if ($log && $log->status === 'queued') {
            $sms->deliver($log);
        }
    }
}
