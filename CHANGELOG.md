# Changelog

## 2.0.0 — unreleased

### Security
- Require `livewire/livewire` `^3.8.3|^4.4.8`. Older releases are affected by GHSA-g3hc-697w-wm82 (CVE-2026-81887) and GHSA-mhf7-xp6r-6qrc, among others. See `docs/security-review.md`.
- Fixed: in production, locked-property writes (Livewire 3.8+ and 4) and unknown method calls (Livewire 4) were neither counted nor logged. These exceptions report themselves, which stops Laravel before Shield's callback ran. Detection now happens in the middleware.

### Added
- Exception categories `tampering`, `payload_limit` and `stale_component`, each with a configurable status and an option to report.
- More tampering exceptions, including `MissingFileUploadsTraitException`, `MethodNotFoundException`, `NonPublicComponentMethodCall`, `PropertyNotFoundException`, and `DirectlyCallingLifecycleHooksNotAllowedException`.
- A payload guard for Livewire 3 (bytes, components, calls, updates). Livewire 4 uses its own `livewire.payload` limits.
- An optional rate limit on Livewire update traffic: per guest or user, with a tenant resolver. Off by default.
- An `X-Shield-Incident` header with a server-generated id, also written to the log line.
- A log budget per address, plus a single `livewire.lockout` line.
- `@livewireShieldScripts`: an opt-in client notice for 401/403/404/413/419/429/500/503, with a custom notifier callback.
- English and Dutch messages.
- `php artisan livewire-shield:doctor` (read-only).
- CI: a Livewire 3/4 matrix, `composer audit`, Pint and Larastan.

### Changed
- In debug mode the original response is kept, so developers see the error. It is still logged and counted.
- Log lines are now `livewire.<category>`, with `category`, `incident_id`, `status` and `component` added.

## 1.0.0

- Initial release.
