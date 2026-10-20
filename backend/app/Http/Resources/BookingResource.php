<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trip = $this->trip;
        // On a many-starts trip each pickup point leaves at its own time.
        $leaves = $trip && $trip->pricedByOrigin() ? $trip->departsAtFor($this->pickup_point_id) : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'source' => $this->source ?? 'online',
            'passenger_name' => $this->passenger_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'amount' => (float) $this->amount,
            'currency' => config('busbooking.currency'),
            'seats' => $this->seats->pluck('seat_number')->values(),
            'seat_total' => $this->seats->count(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'pickup_point' => $this->pickupPoint?->name,
            'dropoff_point' => $this->destination, // the destination, only on a trip with several destinations
            'start_point' => $this->journey_from, // the starting place, only on a trip with several starting places
            'pickup_stop' => $this->pickupPoint ? ['order' => $this->pickupPoint->stop_order, 'is_main' => (bool) $this->pickupPoint->is_main_station] : null,
            'moved' => $this->moved_at !== null,
            'no_show_at' => $this->no_show_at?->toIso8601String(),
            'trip' => $trip ? [
                'id' => $trip->id,
                'status' => $trip->status,
                'travel_date' => $trip->travel_date->format('Y-m-d'),
                'departure_time' => $leaves ? $leaves->format('H:i') : ($trip->departure_time ? substr($trip->departure_time, 0, 5) : null),
                'departure_time_label' => $leaves ? $leaves->format('g:i A') : $trip->timeLabel(),
                'seat_selection' => $trip->relationLoaded('bus') ? ($trip->bus?->allowsSeatSelection() ?? true) : true,
                'fill_and_go' => $trip->route?->mode === \App\Models\BusRoute::FILL_AND_GO,
                'origin' => $trip->route?->origin,
                'destination' => $trip->route?->destination,
                'bus' => $trip->relationLoaded('bus') ? $trip->bus?->name : null,
                'bus_registration' => $trip->relationLoaded('bus') ? $trip->bus?->registration_number : null,
            ] : null,
            'payment' => $this->payment ? [
                'status' => $this->payment->status,
                'reference' => $this->payment->reference,
                'channel' => $this->payment->channel,
                'paid_at' => $this->payment->paid_at?->toIso8601String(),
            ] : null,
            'arrival' => [
                'confirmed' => $this->confirmed_at !== null,
                'confirmed_at' => $this->confirmed_at?->toIso8601String(),
                'confirmed_by' => $this->confirmedBy?->name,
                'confirmed_by_id' => $this->confirmed_by,
            ],
        ];
    }
}
