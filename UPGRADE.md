# Upgrade guide

## 1.x → 2.0

1. Update Livewire first, to at least 3.8.3 (Livewire 3) or 4.4.8 (Livewire 4):
   ```bash
   composer update livewire/livewire
   composer require monkeysoftnl/laravel-livewire-shield:^2.0
   ```
2. If you published the config, compare it with the new `config/livewire-shield.php`. The existing keys (`max_attempts`, `decay_seconds`, `middleware_group`, `log_channel`) keep their meaning. Missing new keys fall back to their defaults.
3. Behaviour changes to check:
   - **Locked-property writes are now counted in production.** In 1.x they were silently missed, so addresses can reach `max_attempts` sooner than before.
   - **Oversized payloads now answer `413`** (Livewire 4's own limits included), and stale components answer `419`. Previously these were a 500 or Livewire's own response. Tampering stays `403`.
   - **With `APP_DEBUG=true` the original error response is kept** instead of the 403.
   - **Log messages are now `livewire.<category>`.** Tampering stays `livewire.tampering`. Update any log alerts that match on exact context keys.
4. Optional: add `@livewireShieldScripts` to your layout, and run `php artisan livewire-shield:doctor`.

`TamperingDetector::isTampering()` and `BlockTamperedLivewireRequests::recordAttempt()` still exist for 1.x callers.
