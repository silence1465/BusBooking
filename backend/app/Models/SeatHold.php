<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trip_id', 'seat_id', 'booking_id', 'status', 'expires_at'])]
class SeatHold extends Model
{
    public const HELD = 'held';
    public const BOOKED = 'booked';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
