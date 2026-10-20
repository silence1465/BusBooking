<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bus_id', 'seat_number'])]
class Seat extends Model
{
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
