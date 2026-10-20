<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Models\Setting;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function stats(DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->stats()]);
    }

    public function smsLogs(Request $request): JsonResponse
    {
        $logs = SmsLog::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(20);

        // Counts for the cards. Message type is read from the wording of the texts the system sends.
        $types = [
            'tickets' => 'Booking confirmed%',
            'boarding' => '%is now boarding%',
            'almost_full' => '%almost full%',
            'pickup' => '%has left%',
            'missed' => '%missed your bus%',
        ];
        $select = ['count(*) as total', "coalesce(sum(status = 'sent'), 0) as sent", "coalesce(sum(status = 'queued'), 0) as remaining", "coalesce(sum(status = 'failed'), 0) as failed"];
        $bindings = [];
        foreach ($types as $key => $like) {
            $select[] = "coalesce(sum(message like ?), 0) as $key";
            $bindings[] = $like;
        }
        $summary = SmsLog::query()->selectRaw(implode(', ', $select), $bindings)->first()->toArray();

        return response()->json(array_merge($logs->toArray(), ['summary' => array_map('intval', $summary)]));
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => Setting::allWithDefaults()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        // The map can be pasted as the whole <iframe ...> code from Google Maps: keep just its address.
        if (is_string($request->input('contact_map_embed'))) {
            $raw = trim($request->input('contact_map_embed'));
            if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)) {
                $raw = html_entity_decode($m[1]);
            }
            $request->merge(['contact_map_embed' => $raw]);
        }

        $data = $request->validate([
            'company_name' => ['sometimes', 'required', 'string', 'max:120'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'seat_hold_minutes' => ['sometimes', 'required', 'integer', 'min:3', 'max:60'],
            'contact_intro' => ['sometimes', 'nullable', 'string', 'max:600'],
            'contact_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]*$/'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'contact_address' => ['sometimes', 'nullable', 'string', 'max:400'],
            'contact_hours' => ['sometimes', 'nullable', 'string', 'max:400'],
            // Only Google's own embed address: nothing else is ever put in an iframe on the customer site.
            'contact_map_embed' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:#^https://www\.google\.com/maps/embed\?#'],
            'contact_map_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'page_privacy' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sms_tickets' => ['sometimes', 'in:0,1'],
            'sms_boarding' => ['sometimes', 'in:0,1'],
            'sms_almost_full' => ['sometimes', 'in:0,1'],
            'sms_pickup' => ['sometimes', 'in:0,1'],
            'sms_missed' => ['sometimes', 'in:0,1'],
            'ads_enabled' => ['sometimes', 'in:0,1'],
            'ads_publisher_id' => ['sometimes', 'nullable', 'regex:/^ca-pub-\d{10,20}$/'], // e.g. ca-pub-1234567890123456
            'ads_slot_home' => ['sometimes', 'nullable', 'regex:/^\d{6,20}$/'],
            'ads_slot_results' => ['sometimes', 'nullable', 'regex:/^\d{6,20}$/'],
            'ads_slot_confirmation' => ['sometimes', 'nullable', 'regex:/^\d{6,20}$/'],
        ]);

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => (string) ($value ?? '')]);
        }

        return response()->json(['data' => Setting::allWithDefaults()]);
    }
}
