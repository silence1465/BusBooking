<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    /** Settings editable from the admin UI, with defaults. */
    public const DEFAULTS = [
        'company_name' => 'Bus Booking',
        'support_phone' => '',
        'seat_hold_minutes' => '10',
        // Which automatic texts are sent (1 = on, 0 = off).
        'sms_tickets' => '1',
        'sms_boarding' => '1',
        'sms_almost_full' => '1',
        'sms_pickup' => '1',
        'sms_missed' => '1',
    ];

    /** What the customer's Contact us and Privacy pages say. Empty means "not set": the page shows what it can. */
    public const PAGE_DEFAULTS = [
        'contact_intro' => '',
        'contact_whatsapp' => '',
        'contact_email' => '',
        'contact_address' => '',
        'contact_hours' => '',
        'contact_map_url' => '',
        'contact_map_embed' => '', // the src of a Google Maps embed (only google.com/maps/embed addresses are kept)
        'page_privacy' => '', // when set, replaces the built-in privacy text
    ];

    /** Google AdSense: off until an admin turns it on and pastes the publisher id and ad unit ids. */
    public const AD_DEFAULTS = [
        'ads_enabled' => '0',
        'ads_publisher_id' => '',
        'ads_slot_home' => '',
        'ads_slot_results' => '',
        'ads_slot_confirmation' => '',
    ];

    public const SMS_TYPES = ['tickets', 'boarding', 'almost_full', 'pickup', 'missed'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function smsEnabled(string $type): bool
    {
        return static::get('sms_'.$type, '1') !== '0';
    }

    public static function allWithDefaults(): array
    {
        return array_merge(self::DEFAULTS, self::AD_DEFAULTS, self::PAGE_DEFAULTS, static::query()->pluck('value', 'key')->all());
    }
}
