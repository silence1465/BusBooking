<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Messages an admin writes: saved templates, and sending to a route's passengers or to chosen numbers. */
class SmsController extends Controller
{
    /** The most one send may reach, so a slip cannot text everyone. */
    private const MAX_RECIPIENTS = 500;

    /** Words that are filled in per passenger when sending to a route. */
    public const PLACEHOLDERS = ['name', 'code', 'route', 'pickup', 'date'];

    // ------------------------------------------------------------ templates

    public function templates(): JsonResponse
    {
        return response()->json(['data' => SmsTemplate::orderBy('name')->get(['id', 'name', 'body'])]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate($this->templateRules());

        return response()->json(['data' => SmsTemplate::create($data)], 201);
    }

    public function updateTemplate(Request $request, SmsTemplate $template): JsonResponse
    {
        $template->update($request->validate($this->templateRules($template)));

        return response()->json(['data' => $template]);
    }

    public function destroyTemplate(SmsTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function templateRules(?SmsTemplate $template = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('sms_templates', 'name')->ignore($template?->id)],
            'body' => ['required', 'string', 'max:480'],
        ];
    }

    // ------------------------------------------------------------ recipients and sending

    /** How many people a route send would reach, before anything is sent. */
    public function recipients(Request $request): JsonResponse
    {
        $data = $request->validate($this->audienceRules());
        $people = $this->routePassengers($data);

        return response()->json(['data' => [
            'count' => $people->count(),
            'capped' => $people->count() > self::MAX_RECIPIENTS,
            'sample' => $people->take(5)->map(fn ($p) => ['name' => $p['name'], 'phone' => $p['phone']])->values(),
        ]]);
    }

    /**
     * Who could be texted on a day: every route (and each car on it) with its number of passengers,
     * so the composer can show the count before anything is chosen. One person counts once per route/car.
     */
    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'only_not_checked_in' => ['sometimes', 'boolean']]);
        $date = $data['date'] ?? today()->toDateString();

        $bookings = Booking::with(['trip.route', 'trip.bus'])
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->where('source', 'online')->whereNotNull('phone')->where('phone', '!=', '')
            ->when(! empty($data['only_not_checked_in']), fn ($q) => $q->whereNull('confirmed_at'))
            ->whereHas('trip', fn ($q) => $q->whereDate('travel_date', $date))
            ->get();

        $people = fn (Collection $list) => $list->unique(fn (Booking $b) => Phone::suffix($b->phone))->count();

        $routes = $bookings->groupBy(fn (Booking $b) => $b->trip->route_id)->map(function (Collection $list) use ($people) {
            $route = $list->first()->trip->route;

            return [
                'id' => $route->id,
                'route' => $route->origin.' → '.$route->destination,
                'passengers' => $people($list),
                'trips' => $list->groupBy('trip_id')->map(function (Collection $cars) use ($people) {
                    $trip = $cars->first()->trip;

                    return ['id' => $trip->id, 'bus' => $trip->bus->name, 'registration' => $trip->bus->registration_number, 'time' => $trip->timeLabel(), 'status' => $trip->status, 'passengers' => $people($cars)];
                })->sortBy('time')->values(),
            ];
        })->sortBy('route')->values();

        return response()->json(['data' => ['date' => $date, 'routes' => $routes, 'max' => self::MAX_RECIPIENTS]]);
    }

    public function send(Request $request, SmsService $sms): JsonResponse
    {
        $data = $request->validate(array_merge([
            'message' => ['required', 'string', 'min:2', 'max:480'],
            'audience' => ['required', Rule::in(['route', 'numbers'])],
            'numbers' => ['required_if:audience,numbers', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'numbers.*' => ['string', 'max:30'],
        ], $this->audienceRules(false)));

        $targets = $data['audience'] === 'route' ? $this->routePassengers($data) : $this->typedNumbers($data);

        if ($targets->isEmpty()) {
            throw new BookingException('There is nobody to send this to.', 422);
        }
        if ($targets->count() > self::MAX_RECIPIENTS) {
            throw new BookingException('That is more than '.self::MAX_RECIPIENTS.' people. Narrow it down by day or by checked-in status.', 422);
        }

        foreach ($targets as $person) {
            // Queued like every other text: this request does not wait for the SMS provider.
            $sms->send($person['phone'], $this->fill($data['message'], $person), $person['booking_id'] ?? null);
        }

        return response()->json(['data' => ['queued' => $targets->count()]]);
    }

    private function audienceRules(bool $required = true): array
    {
        return [
            'route_id' => [$required ? 'required' : 'required_if:audience,route', 'integer', 'exists:routes,id'],
            'trip_id' => ['nullable', 'integer', 'exists:trips,id'], // just one car on the route
            'date' => ['nullable', 'date_format:Y-m-d'],
            'only_not_checked_in' => ['sometimes', 'boolean'],
        ];
    }

    /** One entry per phone number among the confirmed passengers of a route's trips on a day (today by default). */
    private function routePassengers(array $data): Collection
    {
        $date = $data['date'] ?? today()->toDateString();

        return Booking::with(['trip.route', 'pickupPoint'])
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->where('source', 'online') // counter sales leave no phone number
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->when(! empty($data['only_not_checked_in']), fn ($q) => $q->whereNull('confirmed_at'))
            ->whereHas('trip', fn ($q) => $q->where('route_id', $data['route_id'])->whereDate('travel_date', $date)->when($data['trip_id'] ?? null, fn ($w, $id) => $w->whereKey($id)))
            ->orderBy('id')->get()
            ->unique(fn (Booking $b) => Phone::suffix($b->phone))
            ->map(fn (Booking $b) => [
                'booking_id' => $b->id,
                'phone' => $b->phone,
                'name' => $b->passenger_name,
                'code' => $b->code,
                'route' => $b->trip->route->origin.' to '.$b->trip->route->destination,
                'pickup' => $b->pickupPoint?->name ?? 'the station',
                'date' => $b->trip->travel_date->format('j M'),
            ])->values();
    }

    private function typedNumbers(array $data): Collection
    {
        if (str_contains($data['message'], '{')) {
            throw new BookingException('Words in { } are only filled in when sending to a route’s passengers. Remove them to send to numbers.', 422);
        }

        $bad = [];
        $numbers = collect($data['numbers'])->map(fn ($n) => Phone::normalize($n))->filter(function ($n, $i) use ($data, &$bad) {
            $ok = (bool) preg_match('/^(0\d{9}|\d{11,15})$/', $n);
            $ok || $bad[] = $data['numbers'][$i];

            return $ok;
        })->unique()->values();

        if ($bad) {
            throw new BookingException('These do not look like phone numbers: '.implode(', ', array_slice($bad, 0, 5)).'.', 422);
        }

        return $numbers->map(fn ($n) => ['phone' => $n, 'booking_id' => null]);
    }

    private function fill(string $message, array $person): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => in_array($m[1], self::PLACEHOLDERS, true) ? ($person[$m[1]] ?? '') : $m[0], $message);
    }
}
