<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'trip_id', 'pickup_point_id', 'passenger_name', 'phone', 'email', 'amount'])]
class Booking extends Model
{
    use SoftDeletes;

    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';
    public const NO_SHOW = 'no_show'; // forfeited: did not board at a pickup point

    public const STATUSES = [self::PENDING, self::CONFIRMED, self::CANCELLED, self::COMPLETED, self::NO_SHOW];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'moved_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class)->withTrashed();
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }

    public function seats(): HasMany
    {
        return $this->hasMany(BookingSeat::class)->orderBy('seat_number');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->payment?->status === Payment::PAID;
    }
}
