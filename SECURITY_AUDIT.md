# Bus Booking security audit

Date: 2026-10-12 (UTC)

Scope: local Laravel API and React client, route authorization, authentication, booking holds, payment verification and webhooks, uploads, configuration, and locked dependency advisories. No production endpoint was attacked. Application code and dependency versions were unchanged; the audit adds this report and isolated reproduction tests.

## Results

Four actionable findings: one high availability risk and three medium security defects. Severity is contextual, not a formal CVSS score. Five evidence tests passed (52 assertions), including one test documenting the payment-reference access model. These tests intentionally assert current vulnerable behavior; passing them does not mean the application is secure.

Existing backend suite: 96 tests passed, 725 assertions. Composer locked-package audit reported no advisories or abandoned packages. npm audit reported zero vulnerabilities. Advisory checks describe registry results at audit time, not a guarantee against unknown vulnerabilities.

## 1. High: anonymous guests can reserve an entire bus without payment

Sources: [booking cap](backend/config/busbooking.php:6), [hold creation](backend/app/Services/BookingService.php:52), [public booking route](backend/routes/api.php:40).

The configured guest booking cap is 100 seats. A single unauthenticated request can hold all seats on a normal bus for the configured hold period (10 minutes by default). Payment initialization happens after the holds are created, and successful payment is not required to retain a pending hold. The booking rate limit is 10 requests per minute per IP, which permits one caller to block multiple departures within that limit. This is an abuse risk in an otherwise valid bulk-booking feature, rather than a double-booking bug.

Reproduction: `test_guest_can_hold_entire_bus_without_payment` reserves all 45 seats with one guest request; another customer's booking receives 409. Tests use the fake gateway and in-memory SQLite, so they establish local hold behavior, not Paystack throughput or a live-service outage.

Recommended fix: use a smaller anonymous online cap, separate from trusted station bulk sales; limit outstanding holds per trip and verified identity, with an aggregate abuse budget. Require verification or a deposit for large online reservations. Acceptance: ordinary guests cannot monopolize a vehicle in one request, and repeated reservations cannot bypass the outstanding-hold budget.

## 2. Medium: phone aliases bypass account login throttling

Sources: [limiter key](backend/app/Providers/AppServiceProvider.php:50), [phone login lookup](backend/app/Http/Controllers/Api/AuthController.php:45), [phone normalization](backend/app/Support/Phone.php:16).

The limiter uses the raw login identifier plus IP; authentication normalizes phone numbers. Multiple formatted identifiers therefore target the same account while receiving independent attempt budgets. Phone normalization strips arbitrary punctuation, so the alias space exceeds the six conventional representations used in the test. There is no independent IP-wide login budget. Cycling usernames also avoids the per-identifier budget when password spraying.

Reproduction: `test_phone_aliases_bypass_account_login_limit` makes 30 wrong-password attempts against one phone account from the same IP without 429; only the sixth attempt within each of the six aliases is throttled.

Recommended fix: canonicalize identifiers before computing limiter keys; combine an account budget with a separate IP budget and progressive delays. Acceptance: attempts against equivalent phone aliases share one budget, and cycling accounts still hits the IP budget.

## 3. Medium: driver search bypasses bus-specific passenger restrictions

Sources: [station search](backend/app/Http/Controllers/Api/Station/StationController.php:56), [search route scope](backend/app/Http/Controllers/Api/Station/StationController.php:65), [trip authorization](backend/app/Models/User.php:85), [passenger response](backend/app/Http/Resources/BookingResource.php:25).

A driver linked to a specific bus is restricted to that bus by `canWorkOnTrip`, which the manifest and check-in paths enforce. Phone search applies only assigned-route restrictions. A driver can supply a phone number or at least seven digits and receive bookings on another bus serving an assigned route, including passenger identity, phone/email, ticket code, and payment reference.

Reproduction: `test_driver_search_exposes_other_bus_on_same_route` establishes that the driver's trip authorization fails and the manifest returns 403, while the phone search returns that passenger's booking and phone.

Recommended fix: centralize query scoping for accessible trips and enforce bus restrictions consistently in search and other driver endpoints. Acceptance: search cannot return a booking whose trip fails `canWorkOnTrip`.

## 4. Medium: staff password resets leave old tokens valid indefinitely

Sources: [password update](backend/app/Http/Controllers/Api/Admin/StationMasterController.php:86), [revocation only on deactivation](backend/app/Http/Controllers/Api/Admin/StationMasterController.php:91), [token issuance](backend/app/Http/Controllers/Api/AuthController.php:54), [no token expiry](backend/config/sanctum.php:53).

Changing a staff password updates the password hash but does not revoke existing Sanctum tokens. A previously stolen token retains staff access after an admin resets the password. Tokens have neither a global lifetime nor an explicitly supplied expiration at issuance.

Reproduction: `test_staff_password_reset_keeps_existing_bearer_token_valid` changes the staff password through the admin API, clears cached authentication guards, and successfully retrieves a protected manifest using the token issued before the reset.

Recommended fix: revoke all tokens on password changes, preferably in the same transaction as the password update. Set an appropriate token lifetime and provide session revocation. Acceptance: the old bearer token receives 401 immediately after a password reset.

## Additional privacy and hardening observations

- Payment references are bearer credentials for passenger data: [public verification](backend/app/Http/Controllers/Api/Public/PaymentController.php:19) returns the complete booking without phone or ownership verification. The evidence test confirms this behavior. References are cryptographically random (16 characters), so no practical guessing attack was established. A leaked reference is sufficient to recover a booking code and phone, bypassing the separate ticket lookup's two-field requirement. Consider a separate expiring checkout capability and return minimal public payment status.
- [The callback](frontend/src/pages/customer/PaymentCallback.jsx:25) places ticket code and phone in the URL, and [the ticket page](frontend/src/pages/customer/TicketPage.jsx:72) can load an advertising script on confirmation. URLs may be recorded in browser history and hosting logs; third-party scripts executing in the page can read its URL, DOM, and [persisted authentication tokens](frontend/src/lib/api.js:13). No malicious advertising behavior or exploitable XSS was established. Remove phone credentials from URLs, keep sensitive ticket/authentication pages free of third-party scripts, and review token storage and a deployment CSP.
- Local configuration has `APP_ENV=local` and `APP_DEBUG=true`. These are normal development settings, not evidence of a production exposure. Production debug settings, HTTPS, reverse-proxy headers, secret rotation, log retention, and server-level security headers require deployment verification. Secret values were not printed or included in this report. No Git repository was available at the workspace root, so committed-secret history could not be audited.

## Controls observed

Public registration validates input and cannot mass-assign staff roles. Protected staff/admin routes enforce API roles; disabled staff accounts have tokens deleted. Ticket lookup requires both code and matching phone. Paystack webhooks use constant-time HMAC verification, and payment finalization checks provider amount and currency. Booking holds use transactions and a unique seat constraint. Uploads validate image type and size. No direct SQL injection or unsafe HTML rendering sink was found in the reviewed application paths; this is not exhaustive proof of absence.

## Reproduce the local audit evidence

From `backend`, run:

```powershell
php -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml ../security-audit/SecurityAuditTest.php
php -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml
composer audit --locked --format=json --no-interaction
```

From `frontend`, run:

```powershell
npm.cmd audit --json --ignore-scripts
```

The PHP tests use the existing testing configuration with an in-memory SQLite database and fake payment/log SMS providers. The audit evidence suite is kept outside the regular suite to avoid treating vulnerable behavior as a required product feature. Convert these demonstrations into tests of the secure outcomes when implementing fixes. MySQL concurrency and real gateway behavior were not independently retested in this audit.
