<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A different online commission per seat for one vehicle type on one route. */
#[Fillable(['route_id', 'vehicle_category_id', 'commission'])]
class RouteCommission extends Model
{
    protected function casts(): array
    {
        return ['commission' => 'decimal:2'];
    }
}
