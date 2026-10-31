# Security review

**Date checked:** 2026-10-31
**Scope:** `monkeysoftnl/laravel-livewire-shield` 2.0, and the advisories against the dependency range it supports (Livewire 3/4, Laravel 12/13).

## Sources

- Livewire repository advisories: https://github.com/livewire/livewire/security/advisories, fetched with `gh api repos/livewire/livewire/security-advisories`.
- Laravel repository advisories: https://github.com/laravel/framework/security/advisories, fetched with `gh api repos/laravel/framework/security-advisories`.
- GitHub Advisory Database (`gh api advisories?ecosystem=composer&affects=livewire/livewire`).
- `composer audit` (Packagist / FriendsOfPHP data) on the development lock file.
- Livewire source for 3.8.11 and 4.4.8: exception classes, `HandleRequests`, `dist/livewire.esm.js`. Laravel 13.38 `Illuminate\Foundation\Exceptions\Handler`.

Not checked: closed vendor channels, and advisories published after the date above. Re-run `composer audit` before relying on this list.

## Advisories

### Livewire (direct dependency, enforced in `composer.json`)

| ID | Summary | Affected | Patched | Measure |
| --- | --- | --- | --- | --- |
| GHSA-mhf7-xp6r-6qrc | Authorization bypass in `#[Authorize]` via reordered parameter keys and `#[Json]` execution order | >= 4.3.0, < 4.4.8 | 4.4.8 | Livewire 4 floor 4.4.8 |
| GHSA-g3hc-697w-wm82 / CVE-2026-81887 | DOM-based XSS during client-side state handling | 3.0.0-beta.1 – 3.8.2; 4.0.0-beta.1 – 4.3.3 | 3.8.3; 4.3.4 | Livewire 3 floor 3.8.3 |
| GHSA-29cq-5w36-x7w3 / CVE-2025-54068 | Remote command execution during property update hydration | >= 3.0.0-beta.1, < 3.6.4 | 3.6.4 | covered by 3.8.3 |
| GHSA-f3cx-396f-7jqp / CVE-2024-47823 | Remote code execution on file uploads | >= 3.0.0-beta.1, < 3.5.2 (and < 2.12.7) | 3.5.2 | covered |
| GHSA-389c-cf87-qmwj / CVE-2024-21504 | Cross-site scripting | >= 3.3.5, < 3.4.9 | 3.4.9 | covered |
| GHSA-2cjh-75gp-34gc / CVE-2024-22859 | Cross-site request forgery | >= 3.0.0, < 3.0.4 | 3.0.4 | covered |
| GHSA-qwvp-268g-jjm8 | Data leakage | >= 2.2.5, < 2.2.6 | 2.2.6 | not in supported range |

### Laravel (application-level; reported by the doctor, not enforced)

The package depends on `illuminate/support`, not on `laravel/framework`. Pinning the framework would hand this package the job of the application's own dependency management, so these are reported and not enforced.

| ID | Summary | Affected | Patched |
| --- | --- | --- | --- |
| GHSA-f9x3-7vq9-cph6 / CVE-2026-114509 | Token-based API guard auth bypass | < 12.68.0; < 13.29.0 | 12.68.0; 13.29.0 |
| GHSA-5wcm-3v2w-8362 / CVE-2026-114508 | Unauthorized data destruction | >= 12.45.0, < 12.68.0; < 13.27.0 | 12.68.0; 13.27.0 |
| GHSA-jh5r-qr3c-85q8 / CVE-2026-102279 | XSS in debug page information | < 12.69.0; < 13.30.0 | 12.69.0; 13.30.0 |
| GHSA-crmm-hgp2-wgrp / CVE-2026-48041 | Temporary signed URL path confusion (relevant to Livewire's signed upload URLs) | < 12.61.1; < 13.12.0 | 12.61.1; 13.12.0 |
| GHSA-5vg9-5847-vvmq / CVE-2026-48019 | CRLF injection in default email rule | < 12.60.0; <= 13.9.0 | 12.60.0; 13.10.0 |

The doctor's minimums are therefore 12.69.0 and 13.30.0.

### Development dependencies

At the time of checking, `composer audit` on the old lock file reported phpunit/phpunit 13.4.3 (CVE-2026-119193, shared temp directory, local only) and symfony/mime 8.1.7 (CVE-2026-45070, email header injection). Both are transitive dev dependencies, not shipped with the package. After `composer update`, `composer audit` reports no advisories.

## Findings in the package itself

| Type | Finding | Status |
| --- | --- | --- |
| Confirmed bug | `CannotUpdateLockedPropertyException` (Livewire 3.8+ and 4) and `MethodNotFoundException` (Livewire 4) define `report()`, which returns `true` in production. Laravel's `Handler::reportThrowable` then returns before any `reportable` callback, so 1.x never counted or logged these attempts. | Fixed: detection moved to the middleware, which reads the exception the routing pipeline attaches to the response. Regression test in `tests/IntegrationTest.php`. |
| Possible risk | Attempt counting is per address. Shared addresses (NAT, corporate proxies) can lock out bystanders after `max_attempts`. | Documented; tune `max_attempts` / `decay_seconds`. |
| Possible risk | With `APP_DEBUG=true` the original error page is returned. | Intended for development; the doctor warns in production. |
| Hardening | Payload guard for Livewire 3, an optional update rate limit, a log budget, sanitized identifiers in logs, and a text-only client notice. | Added. |

## Category coverage

- **Property hydration and deserialization:** upstream fixes (CVE-2025-54068). Shield classifies hydration `TypeError`s and checksum failures as tampering.
- **File uploads:** upstream fixes (CVE-2024-47823). Shield classifies `MissingFileUploadsTraitException`. Validation, storage and malware scanning remain application work (see README).
- **DOM XSS and URL/state handling:** upstream fixes (CVE-2026-81887, CVE-2024-21504). Shield's own script only uses `textContent`.
- **Authorization bypass:** upstream fix (GHSA-mhf7-xp6r-6qrc). Shield cannot infer business authorization.
- **Public properties and action parameters:** Shield classifies Livewire's refusals. Authorizing every action stays in application code.
- **Snapshot/checksum integrity:** Livewire's checksum is left intact. A mismatch is classified as tampering.
- **CSRF and sessions:** untouched. `TokenMismatchException` keeps Laravel's 419. The client script only offers a reload.
- **Dependencies:** `composer audit` runs in CI and weekly.

## Out of scope

Shield does not address vulnerabilities in the Linux kernel, the web server, PHP itself, TLS, or the infrastructure. It does not replace upstream security updates or application-specific authorization.
