<?php

return [
    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),
    'seat_hold_minutes' => (int) env('SEAT_HOLD_MINUTES', 10),
    'max_seats_per_booking' => 100, // no real cap: the largest vehicle. Free seats are what limits a booking.

    // Fill-and-go vehicles
    'boarding' => [
        'almost_full_seats' => 3,   // text main-station passengers when this few seats remain
        'grace_minutes' => 10,      // how long a full vehicle waits for main-station passengers
    ],
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),
    'guest_email_domain' => 'guest.busbooking.app',

    'payment' => [
        'driver' => env('PAYMENT_DRIVER', 'paystack'), // paystack | fake
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'arkesel'), // arkesel | log
        // after_response: sent the moment the HTTP response has gone out (no worker needed).
        // queue: pushed on the "sms" queue for a worker (php artisan queue:work --queue=sms) when volume is high.
        'delivery' => env('SMS_DELIVERY', 'after_response'),
        'arkesel' => [
            'api_key' => env('ARKESEL_API_KEY'),
            'sender_id' => env('ARKESEL_SENDER_ID', 'BusBooking'),
            'base_url' => env('ARKESEL_BASE_URL', 'https://sms.arkesel.com/api/v2'),
        ],
    ],
];
