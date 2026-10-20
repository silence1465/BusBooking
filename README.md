# Bus Booking System (Version 1)

API-first bus booking: **Laravel 13 + Sanctum + MySQL** backend, **React + Vite + Tailwind + Axios** PWA frontend.
The React app is only one client of the REST API (`/api/v1`), so Android/iOS apps can use the same backend later.

```
backend/    Laravel API
frontend/   React PWA (customer site, station master, admin)
```

## Run it locally

Requirements: PHP 8.3+, Composer, Node 20+, MySQL/MariaDB.

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate     # then set DB_* in .env
php artisan migrate --seed
php artisan serve                                     # http://localhost:8000

# Frontend (second terminal)
cd frontend
npm install
cp .env.example .env                                  # VITE_API_URL=http://localhost:8000/api/v1
npm run dev
```

If your Vite dev port is not 5173, add that origin to `CORS_ALLOWED_ORIGINS` and set `FRONTEND_URL` in `backend/.env`.

Release-seat cleanup runs through the scheduler (`php artisan schedule:work` in dev, `* * * * * php artisan schedule:run` in production). Seats are also freed lazily on every booking/availability request, so correctness never depends on the scheduler.

### Seed accounts (development only, password: `password`)

| Role | Email |
|---|---|
| Admin | admin@busbooking.test |
| Station Master | station@busbooking.test |
| Customer | customer@busbooking.test |
| Driver | driver@busbooking.test |

Seed data also contains 2 buses (GT 1234-26 with 45 seats, GR 5678-26 with 33), Accra⇄Kumasi routes, pickup points (Circle, Achimota, Nsawam) and trips for the next few days. In production set `SEED_PASSWORD` before seeding (the seeder refuses the default password there).

## Going live: Paystack and Arkesel

In `backend/.env` (never in React):

```
APP_ENV=production
APP_DEBUG=false
PAYMENT_DRIVER=paystack
PAYSTACK_SECRET_KEY=sk_live_...
SMS_DRIVER=arkesel
ARKESEL_API_KEY=...
ARKESEL_SENDER_ID=YourBrand
FRONTEND_URL=https://your-site
CORS_ALLOWED_ORIGINS=https://your-site
```

* In the Paystack dashboard set the webhook URL to `https://your-api/api/v1/payments/webhook`. The callback URL is sent per transaction (`FRONTEND_URL/payment/callback`).
* `PAYMENT_DRIVER=fake` (default in the dev `.env`) skips Paystack and treats every payment as successful. It is **refused when `APP_ENV=production`**.
* `SMS_DRIVER=log` writes messages to `sms_logs` only, without sending anything.

## How the important parts work

**No double-booking.** Creating a booking runs in a DB transaction that locks the trip row (`SELECT ... FOR UPDATE`), clears expired holds, checks the seats, and inserts rows into `seat_holds`. `seat_holds` has a `UNIQUE(trip_id, seat_id)` key, so even a bug could not sell a seat twice. Verified with 8 parallel PHP processes racing for one seat on MySQL: exactly one succeeded.

**Seat lifecycle.** `HELD` (10 min, configurable in Admin → Settings) → `BOOKED` on verified payment; on failure/expiry the hold row is deleted so the seat is `AVAILABLE` again. If a customer pays a moment *after* their hold expired, the backend re-claims the seats if nobody else took them; otherwise the payment is flagged "refund required" in Admin → Payments.

**Payments never trust the frontend.** The browser callback only sends the payment *reference* to `POST /payments/verify`. The backend calls Paystack's verify endpoint itself, checks amount and currency, then confirms the booking. The webhook (HMAC-SHA512 signature checked) goes through the same idempotent code path, so callback + webhook together can't double-confirm or double-SMS.

**SMS is isolated.** It is sent after the payment transaction commits, inside a catch-all; failures are logged in `sms_logs` and never affect the booking.

**Booking codes** are `BUS-` + 6 random characters from an unambiguous alphabet (CSPRNG, uniqueness enforced by the database).

**Permissions** are enforced by the API (`role:` middleware on every route group). Station masters get 403 on every `/admin/*` route. `role` and `is_active` are not mass-assignable, so registration cannot create staff.

**Guest tickets.** A ticket needs the booking code **and** the booking phone number; code-lookup endpoints are rate limited.

## API overview (`/api/v1`)

| Area | Endpoints |
|---|---|
| Auth | `POST auth/login`, `auth/register`, `auth/logout`, `GET auth/me` |
| Public | `GET locations`, `trips/search?from&to&date`, `trips/{id}` (seat map), `POST bookings`, `GET tickets/{code}?phone=`, `POST payments/verify`, `POST payments/webhook` |
| Customer | `GET my/bookings` |
| Station (station master or admin) | `POST station/verify`, `POST station/bookings/{code}/confirm-arrival`, `GET station/search?phone=`, `GET station/trips`, `GET station/trips/{id}/manifest` |
| Admin | `dashboard`, `buses`, `routes`, `pickup-points`, `trips`, `station-masters` (CRUD), `bookings` (+ `POST bookings/{id}/cancel`), `payments` (+ `POST payments/{id}/refunded`), `sms-logs`, `settings` |

Mobile apps authenticate with the same bearer token returned by `auth/login` (Sanctum personal access tokens). CSRF protection is not needed for bearer-token APIs; there are no cookie sessions.

Rate limits: login 5/min, search 60/min, booking 10/min, code/phone lookups 20/min, station 90/min.

## Tests

```bash
cd backend && php artisan test      # 20 feature tests: booking, seat races, payments, webhook, roles, station flow
cd frontend && npm run build
```

## Fill-and-go routes (no timetable)

For routes where the vehicle leaves when it is full (set **Departure type** on the route in Admin, plus a fare, and mark one pickup point as the **main station**; give the others a stop order):

* A station master opens a vehicle (**Vehicles and trips → Open a vehicle for boarding**). Customers see "Leaves when full" and book it online; the station master sells seats from the same seat map.
* When seats get low (3 left), passengers booked to board at the station get an "almost full, be at the station" text. Passengers boarding at later pickup points are not texted that.
* **Dispatch** sends the vehicle off. Unchecked main-station passengers block it unless the station master chooses to leave them behind; pickup-point passengers get "the bus has left, be ready at Achimota".
* **No-show at the main station:** the ticket moves once to the next vehicle (the seat is resold). A second miss forfeits it.
* **No-show at a pickup point:** the ticket is forfeited and the seat stays empty (the vehicle is already moving).
* A **driver** account (Admin → Staff) rides the vehicle: scan QR codes, check passengers in at each stop and mark pickup no-shows. It cannot sell, dispatch or see admin pages.

### The queue and station dues

* Vehicles on a fill-and-go route wait in a **queue**. The front one is *boarding*; the others are *queued*. The station master can move a queued vehicle up or down, put it at the front (only while the front vehicle has no passengers) or remove it (only while it has none).
* Customers are offered the front vehicle; when it is full they are offered the next one. When the front vehicle is dispatched, the next one moves up automatically and its main-station passengers are texted that it is boarding.
* **Station dues:** Admin → *Vehicle types and dues* defines types (e.g. Minibus GHS 10, Coaster GHS 15, Big bus GHS 25). Each bus has a type, and an optional special rate that overrides it. The due is recorded when the vehicle departs (a later rate change never rewrites history) and is marked collected or outstanding; outstanding dues can be marked collected later.
* **Departures** (station console and Admin → *Departures and dues*) is the record of every vehicle that left on a day, grouped by vehicle type or route, with passengers, dues, collected and outstanding totals. It prints.
* Timetabled trips can be dispatched too, so the day's record and dues are complete.

### SMS delivery speed

Texts never hold up a page or a button. Sending an SMS only writes a "queued" row to the SMS log; the provider is called straight after the HTTP response has gone out (`SMS_DELIVERY=after_response`, the default, no worker needed). A failed first attempt is retried once immediately. Admin → SMS logs shows the status and how many seconds each text took.

On a busy station, set `SMS_DELIVERY=queue`, `QUEUE_CONNECTION=database` (or redis) and keep a worker running: `php artisan queue:work --queue=sms`. Run the app under PHP-FPM or Apache (not `php artisan serve`) so "after the response" really means after the customer has their page.

## Version 1 decisions and known limits

* **Multi-seat booking** is supported (max 5 seats, one passenger/contact per booking, one booking code).
* **Seat count is fixed** once a bus has trips. Trips with bookings can't change bus/route/date/time or be deleted; cancel them instead (after cancelling their confirmed bookings).
* **Cancelling a booking** releases the seats but does **not** call Paystack to refund. Refund in the Paystack dashboard, then use *Mark refunded* in Admin → Payments.
* **Station master arrival** for a booking on a different date shows a warning but is still allowed.
* **Admin accounts** are created by the seeder / `tinker`, not through the UI (station masters can't manage admins).
* **PWA:** installable (manifest, icons, service worker caching the app shell). The `public/icon-*.png` files are placeholders: replace them with real branding. API calls are never cached; no offline booking.
* **Not built (by design):** QR codes, USSD, promo codes, multi-company, segment-based seat resale, advanced refunds, push notifications.
