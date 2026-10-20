<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The price of one place on a trip. Either the trip has a fixed start and this is a destination, or it has a fixed
 * destination and this is a starting place (see trips.fares_by). The place alone sets the price.
 * The place's name is stored in the destination column.
 */
#[Fillable(['trip_id', 'pickup_point_id', 'destination', 'price', 'departure_time', 'sort_order'])]
class TripFare extends Model
{
    public function trip(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
