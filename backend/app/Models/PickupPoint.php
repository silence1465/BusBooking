<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['route_id', 'name', 'description', 'status', 'stop_order', 'is_main_station'])]
class PickupPoint extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_main_station' => 'boolean', 'stop_order' => 'integer'];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }
}
