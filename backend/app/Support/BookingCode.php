<?php

namespace App\Support;

use App\Models\Booking;

class BookingCode
{
    // No 0/O/1/I/L so codes are easy to read out over the phone.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        do {
            $code = 'BUS-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Booking::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** Accepts "bus-8k4p29", " 8K4P29 " etc. */
    public static function normalize(string $input): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', $input));

        return str_starts_with($code, 'BUS-') ? $code : (str_starts_with($code, 'BUS') ? 'BUS-'.substr($code, 3) : 'BUS-'.$code);
    }
}
