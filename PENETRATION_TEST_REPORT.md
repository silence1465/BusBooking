# Local API penetration testing

Date: 2026-10-12 (UTC)

## Scope and method

Executed bounded attack requests through the Laravel HTTP kernel, including routing, validation, authentication, authorization, and throttling middleware. The tests use the existing PHPUnit configuration with in-memory SQLite and synthetic users, trips, and passenger details. New scenarios use real Sanctum bearer tokens rather than bypassing authentication with `actingAs`. External HTTP requests are prohibited in the new suite; Paystack responses are mocked, SMS uses the log provider, and no real payment or message is sent.

This is application-level local penetration testing, not a network scan, browser assessment, or production penetration test. No deployed target was provided. Web-server configuration, TLS, reverse proxies, production credentials, browser XSS execution, MySQL concurrency, and sustained load were not tested.

## Outcome

18 scenarios passed with 153 assertions: 13 new attack scenarios plus the 5 existing audit demonstrations. A passing demonstration means its stated behavior was reproduced; it does not mean the vulnerability is fixed. Four existing findings remain exploitable, with one additional demonstrated chain expanding the passenger-data access finding.

| Attack | Observed result | Assessment |
| --- | --- | --- |
| Anonymous reservation of all 45 seats | Pending booking created; second customer blocked with 409 | High availability risk reproduced |
| Phone aliases against login limiter | 30 wrong-password attempts allowed across six equivalent identifiers | Medium throttling bypass reproduced |
| Driver searches another bus on assigned route | Passenger code, phone, and payment reference returned despite manifest 403 | Medium access-control bypass reproduced |
| Old staff token after admin password reset | Protected manifest still accessible | Medium session-revocation defect reproduced |
| Driver search → payment reference → anonymous verification | Anonymous request retrieves victim ticket code and phone | Passenger-data access chain reproduced |
| Guest/customer access to admin, station, and management routes | 401/403 | Tested role boundaries held |
| Registration with `role=admin` and management permissions | Account remains customer; admin API 403 | Privilege escalation blocked |
| Customer accesses another customer's booking list | Attacker list excludes victim booking | Tested ownership boundary held |
| Unassigned station check-in, dispatch, trip update, manifest | Requests rejected; arrival and fare unchanged | Tested route boundaries held |
| Booking price, status, identity, source, commission tampering | Server retains fare, pending status, guest identity, online source | Tested protected fields held |
| Cross-route pickup and duplicate seats | 422; no bookings or holds created | Validation held |
| Three SQL payloads in login, phone lookup, and trip search | Authentication/lookup rejected; searches empty | No injection bypass in tested inputs |
| Client-supplied payment success | Provider pending response wins | Payment spoofing blocked |
| Missing signature or signed body subsequently changed | 401; payment remains pending | Webhook forgery blocked |
| Gateway success with wrong amount or currency | Payment marked failed; booking remains pending | Payment integrity checks held |
| Repeated callback and valid signed webhook | One payment, SMS row, and seat hold | Sequential replay was idempotent |
| PHP bytes uploaded with `.jpg` filename | 422; image path unchanged | Tested disguised upload blocked |
| Lookup guessing with changing ticket codes and phones | First 20 unknown-code requests return 404, next returns 429 | IP lookup budget held |

## Demonstrated passenger-data chain

Precondition: a valid driver token, an assigned route, a bus restriction excluding the victim's bus, and knowledge of at least seven digits of a passenger phone. The test uses a full synthetic phone number.

1. Request `GET /api/v1/station/search?phone=0241234567` with the driver token. It returns the victim's booking and `payment.reference` even though the driver's manifest request for that bus returns 403.
2. Remove authentication and submit the returned reference to `POST /api/v1/payments/verify`.
3. The response includes the victim's ticket code and phone without ownership or phone verification.

The reference is random and is not guessed. The chain exploits the missing bus restriction in search, then the existing reference-based public access model. No extra high-severity payment theft claim is supported: gateway verification still prevents fabricated payments.

Evidence: [chain test](security-audit/PenetrationTest.php:82), [search scope](backend/app/Http/Controllers/Api/Station/StationController.php:65), [public payment response](backend/app/Http/Controllers/Api/Public/PaymentController.php:19).

## Remediation priorities

1. Separate anonymous booking caps from trusted station bulk reservations; enforce outstanding-hold budgets and verify large reservations.
2. Enforce the driver's bus restriction in every passenger query, especially phone search.
3. Normalize login identifiers before throttling and add a separate IP-wide attempt budget.
4. Revoke staff tokens when resetting passwords and set token lifetimes.
5. Return minimal public payment status or require a separate expiring checkout capability before returning passenger data.

Detailed root causes and acceptance criteria are in [SECURITY_AUDIT.md](SECURITY_AUDIT.md). Application source and dependencies were unchanged. Test artifacts include the reproduction suites and this report. A synthetic upload artifact from test development was removed; the final disguised-upload scenario uses a temporary file and inspects its actual MIME type.

## Reproduce

From `backend`:

```powershell
php -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml ../security-audit
```

Observed final output:

```json
{"tool":"phpunit","result":"passed","tests":18,"passed":18,"assertions":153,"duration_ms":4958}
```

The existing 96-test backend suite passed during the preceding security audit. It was not rerun for this test-only addition. Keep these exploit demonstrations outside the normal regression suite until fixes convert their assertions to secure outcomes.
