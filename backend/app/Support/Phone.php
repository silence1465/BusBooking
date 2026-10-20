<?php

namespace App\Support;

class Phone
{
    /** Digits only, e.g. "+233 24-123 4567" => "233241234567". */
    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** Local Ghana style when possible (0241234567), otherwise digits as entered. */
    public static function normalize(string $phone): string
    {
        $d = self::digits($phone);
        if (str_starts_with($d, '233') && strlen($d) === 12) {
            return '0'.substr($d, 3);
        }
        if (strlen($d) === 9) {
            return '0'.$d;
        }

        return $d;
    }

    /** International format for SMS providers (233241234567). */
    public static function international(string $phone): string
    {
        $d = self::digits($phone);
        if (str_starts_with($d, '0') && strlen($d) === 10) {
            return '233'.substr($d, 1);
        }

        return $d;
    }

    /** Last 9 digits, used for loose matching regardless of prefix style. */
    public static function suffix(string $phone): string
    {
        return substr(self::digits($phone), -9);
    }

    public static function matches(string $a, string $b): bool
    {
        $sa = self::suffix($a);

        return $sa !== '' && hash_equals($sa, self::suffix($b));
    }
}
