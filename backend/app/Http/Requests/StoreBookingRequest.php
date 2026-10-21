<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guest booking is allowed
    }

    public function rules(): array
    {
        $max = config('busbooking.max_seats_per_booking');

        return [
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'pickup_point_id' => ['required', 'integer', 'exists:pickup_points,id'],
            // Only for a trip with several destinations: which one (its price follows).
            'destination_id' => ['nullable', 'integer', 'exists:trip_fares,id'],
            // Vehicles with seat selection send seat numbers; open-seating ones (Express, Voxy) send how many seats.
            'seat_numbers' => ['required_without:seat_count', 'array', 'min:1', "max:$max"],
            'seat_numbers.*' => ['required', 'integer', 'min:1', 'distinct'],
            'seat_count' => ['required_without:seat_numbers', 'integer', 'min:1', "max:$max"],
            'passenger_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\s\-]{9,16}$/'],
            'email' => ['required', 'email:rfc', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. 0241234567.',
            'email.required' => 'Email address is required to complete your booking.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
