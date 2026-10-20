<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A group of vehicles that pay the same station due when they depart (e.g. Minibus, Coaster, Big bus). */
#[Fillable(['name', 'station_due', 'seat_selection'])]
class VehicleCategory extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['station_due' => 'decimal:2', 'seat_selection' => 'boolean'];
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class, 'category_id');
    }
}
