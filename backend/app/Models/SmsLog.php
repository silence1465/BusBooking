<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['booking_id', 'phone', 'message', 'provider', 'status', 'provider_response', 'sent_at'])]
class SmsLog extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
