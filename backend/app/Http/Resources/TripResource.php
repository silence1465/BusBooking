<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Trip */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'travel_date' => $this->travel_date->format('Y-m-d'),
            'departure_time' => $this->departure_time ? substr($this->departure_time, 0, 5) : null,
            'departure_time_label' => $this->timeLabel(),
            'fill_and_go' => $this->route?->mode === \App\Models\BusRoute::FILL_AND_GO,
            'price' => (float) $this->price,
            'currency' => config('busbooking.currency'),
            'status' => $this->status,
            'queue_position' => $this->queue_position,
            'station_due' => $this->station_due !== null ? (float) $this->station_due : null,
            'route' => $this->whenLoaded('route', fn () => [
                'id' => $this->route->id,
                'origin' => $this->route->origin,
                'destination' => $this->route->destination,
            ]),
            'bus' => $this->whenLoaded('bus', fn () => [
                'id' => $this->bus->id,
                'name' => $this->bus->name,
                'registration_number' => $this->bus->registration_number,
                'seat_count' => $this->bus->seat_count,
                'image_url' => $this->bus->image_url,
                'seat_selection' => $this->bus->allowsSeatSelection(),
                'category' => $this->bus->category?->name,
            ]),
            'pickup_points' => $this->when(
                $this->relationLoaded('route') && $this->route->relationLoaded('pickupPoints'),
                fn () => $this->route->pickupPoints->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'stop_order' => $p->stop_order, 'is_main_station' => $p->is_main_station])->values(),
            ),
            // One trip, several destinations: the price of each journey, and the cheapest one for "from GHS x".
            'fares' => $this->when($this->relationLoaded('fares') && $this->fares->isNotEmpty(), fn () => $this->fares->sortBy('sort_order')->map(fn ($f) => ['id' => $f->id, 'place' => $f->destination, 'pickup_point_id' => $f->pickup_point_id, 'price' => (float) $f->price, 'departure_time' => $f->departure_time ? substr($f->departure_time, 0, 5) : null, 'departure_time_label' => $f->departure_time ? \Carbon\Carbon::parse($f->departure_time)->format('g:i A') : null])->values()),
            'fares_by' => $this->when($this->relationLoaded('fares') && $this->fares->isNotEmpty() || $request->user('sanctum')?->isAdmin() === true, fn () => $this->fares_by), // destination | origin
            'price_from' => $this->when($this->relationLoaded('fares') && $this->fares->isNotEmpty(), fn () => (float) $this->fares->min('price')),
            'seats_taken' => $this->when($this->resource->getAttribute('seats_taken') !== null, fn () => $this->resource->getAttribute('seats_taken')),
            'available_seats' => $this->when($this->resource->getAttribute('available_seats') !== null, fn () => $this->resource->getAttribute('available_seats')),
            // Admin only: customers and station staff never see the commission.
            'commission' => $this->when($request->user('sanctum')?->isAdmin() === true, fn () => $this->commission !== null ? (float) $this->commission : null),
            'queue_role' => $this->when($this->resource->getAttribute('queue_role') !== null, fn () => $this->resource->getAttribute('queue_role')), // now | next (fill-and-go search results)
            'bookings_count' => $this->whenCounted('bookings'),
        ];
    }
}
