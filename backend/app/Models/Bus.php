<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'registration_number', 'seat_count', 'status', 'category_id', 'station_due_override'])]
class Bus extends Model
{
    use SoftDeletes;

    protected $appends = ['image_url'];

    protected $hidden = ['image_path'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    protected function casts(): array
    {
        return ['seat_count' => 'integer', 'station_due_override' => 'decimal:2'];
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id')->withTrashed();
    }

    /** Open seating (e.g. Express, Voxy): passengers book a number of seats, not seat numbers. */
    public function allowsSeatSelection(): bool
    {
        return $this->category?->seat_selection ?? true;
    }

    /** What this bus pays the station each time it departs: its own special rate, else its category's, else nothing. */
    public function stationDue(): float
    {
        if ($this->station_due_override !== null) {
            return (float) $this->station_due_override;
        }

        return (float) ($this->category?->station_due ?? 0);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)->orderBy('seat_number');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
