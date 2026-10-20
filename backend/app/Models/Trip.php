<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['bus_id', 'route_id', 'travel_date', 'departure_time', 'price', 'fares_by', 'commission', 'status'])]
class Trip extends Model
{
    use SoftDeletes;

    public const SCHEDULED = 'scheduled';
    public const QUEUED = 'queued';     // fill-and-go vehicle waiting its turn behind the boarding one
    public const BOARDING = 'boarding'; // the fill-and-go vehicle at the front, loading at the station
    public const DEPARTED = 'departed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::SCHEDULED, self::QUEUED, self::BOARDING, self::DEPARTED, self::COMPLETED, self::CANCELLED];

    /** Fill-and-go vehicles that are still at the station, front first. */
    public const QUEUE_STATUSES = [self::BOARDING, self::QUEUED];

    protected static function booted(): void
    {
        static::saving(function (Trip $trip) {
            if ($trip->station_due === null && $trip->isDirty('status') && in_array($trip->status, [self::DEPARTED, self::COMPLETED], true)) {
                $trip->loadMissing('bus.category');
                $trip->station_due = $trip->bus?->stationDue() ?? 0;
                if ($trip->status === self::DEPARTED && ! $trip->departed_at) {
                    $trip->departed_at = now();
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'travel_date' => 'date:Y-m-d',
            'price' => 'decimal:2',
            'opened_at' => 'datetime',
            'departed_at' => 'datetime',
            'full_at' => 'datetime',
            'almost_full_notified_at' => 'datetime',
            'due_paid_at' => 'datetime',
            'station_due' => 'decimal:2',
        ];
    }

    public function fares(): HasMany
    {
        return $this->hasMany(TripFare::class);
    }

    /** How a trip with several priced places goes: one start and many destinations, or many starts and one destination. */
    public const FARES_BY_DESTINATION = 'destination';
    public const FARES_BY_ORIGIN = 'origin';

    public function pricedByOrigin(): bool
    {
        return $this->fares_by === self::FARES_BY_ORIGIN;
    }

    /** One trip with several priced places: each destination (or, going the other way, each starting place) has its own price. */
    public function hasFares(): bool
    {
        $this->loadMissing('fares');

        return $this->fares->isNotEmpty();
    }

    /** On a many-starts trip: the price set for boarding at this pickup point, or null if nothing is sold from there. */
    public function fareForPickup(int $pickupPointId): ?TripFare
    {
        $this->loadMissing('fares');

        return $this->fares->first(fn (TripFare $f) => $f->pickup_point_id === $pickupPointId);
    }

    /** The destination (and its price) the passenger picked, or null if it is not one of this trip's destinations. */
    public function fareById(int $fareId): ?TripFare
    {
        $this->loadMissing('fares');

        return $this->fares->first(fn (TripFare $f) => $f->id === $fareId);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class)->withTrashed();
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id')->withTrashed();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function dueCollector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'due_collected_by')->withTrashed();
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    /** Null for a fill-and-go vehicle: it has no timetable and leaves when it is full. */
    public function departsAt(): ?Carbon
    {
        return $this->departure_time
            ? Carbon::parse($this->travel_date->format('Y-m-d').' '.$this->departure_time)
            : null;
    }

    /**
     * When a passenger boarding at this pickup point leaves. On a many-starts trip each pickup point can have its
     * own time; otherwise (or when none is set) it is the trip's time.
     */
    public function departsAtFor(?int $pickupPointId): ?Carbon
    {
        if ($pickupPointId && $this->pricedByOrigin()) {
            $time = $this->fareForPickup($pickupPointId)?->departure_time;
            if ($time) {
                return Carbon::parse($this->travel_date->format('Y-m-d').' '.$time);
            }
        }

        return $this->departsAt();
    }

    /** The latest any pickup point leaves: a trip stays on sale until its last start has gone. */
    public function lastDepartsAt(): ?Carbon
    {
        $latest = $this->departsAt();
        if ($this->pricedByOrigin()) {
            $this->loadMissing('fares');
            foreach ($this->fares as $fare) {
                if ($fare->departure_time) {
                    $at = Carbon::parse($this->travel_date->format('Y-m-d').' '.$fare->departure_time);
                    $latest = $latest && $latest->greaterThan($at) ? $latest : $at;
                }
            }
        }

        return $latest;
    }

    public function isFillAndGo(): bool
    {
        return $this->route?->mode === BusRoute::FILL_AND_GO;
    }

    /** What to show where a time would normally go. */
    public function timeLabel(): string
    {
        return $this->departsAt()?->format('g:i A') ?? 'Leaves when full';
    }

    /**
     * The queue is live, not dated: a vehicle still boarding or waiting when the day ends is still in the queue
     * the next morning, with the passengers already booked on it. Bring such vehicles onto today's date so every
     * list that shows "today" (station, driver, customer search) sees them. Returns how many were carried over.
     */
    public static function carryQueueOver(): int
    {
        return static::query()
            ->whereIn('status', self::QUEUE_STATUSES)
            ->whereDate('travel_date', '<', today())
            ->update(['travel_date' => today()->toDateString()]);
    }

    /**
     * Online commission per seat on this trip: the amount set on the trip itself if there is one, otherwise the
     * route's (or the vehicle type's on that route). Never more than the seat price.
     */
    public function commissionPerSeat(?float $seatPrice = null): float
    {
        $this->loadMissing('route', 'bus');
        $amount = $this->commission !== null ? (float) $this->commission : $this->route->commissionFor($this->bus);

        return min($amount, $seatPrice ?? (float) $this->price);
    }

    /**
     * Can the counter still sell on this trip? Over the counter the passenger is standing there, so a late bus does not
     * close the sale: it stays open until the trip's day ends. (Online, a trip closes once its last pickup point leaves.)
     */
    public function isOpenForCounterSale(): bool
    {
        if (in_array($this->status, self::QUEUE_STATUSES, true)) {
            return true;
        }

        return $this->status === self::SCHEDULED && ! $this->travel_date->copy()->endOfDay()->isPast();
    }

    /** Can seats still be sold on this trip? */
    public function isOpenForSale(): bool
    {
        if (in_array($this->status, self::QUEUE_STATUSES, true)) {
            return true;
        }

        return $this->status === self::SCHEDULED && ($this->lastDepartsAt()?->isFuture() ?? false);
    }
}
