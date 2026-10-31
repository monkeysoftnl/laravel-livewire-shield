# Laravel Livewire Shield

A security and exception-handling layer for Livewire. Requests with hand-edited or oversized payloads get a short status code with no body. Each incident produces one structured log line, and the attempts are counted per address.

Shield is a second line of defence. It does **not** replace keeping Livewire and Laravel up to date, and it does **not** replace authorization in your own code. See [What Shield does not do](#what-shield-does-not-do).

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Livewire 3.8.3+ or 4.4.8+ (older releases have known advisories; see [docs/security-review.md](docs/security-review.md))

## Installation

```bash
composer require monkeysoftnl/laravel-livewire-shield
php artisan livewire-shield:doctor
```

The service provider is auto-discovered. It appends one middleware to the `web` group and registers exception callbacks. It never changes your application files.

Optional:

```bash
php artisan vendor:publish --tag=livewire-shield-config
php artisan vendor:publish --tag=livewire-shield-lang
```

## What Shield does

For requests carrying the `X-Livewire` header:

| Category | Triggered by | Response (production) | Counted |
| --- | --- | --- | --- |
| `tampering` | `CannotUpdateLockedPropertyException`, `CorruptComponentPayloadException` (checksum mismatch), `PublicPropertyNotFoundException`, `PropertyNotFoundException`, `MethodNotFoundException`, `NonPublicComponentMethodCall`, `DirectlyCallingLifecycleHooksNotAllowedException`, `CannotCallComputedDirectlyException`, `CannotMutateReactivePropException`, `MissingFileUploadsTraitException`, and a `TypeError` thrown while Livewire hydrates a property update | `403` | yes |
| `payload_limit` | Livewire 4's `PayloadTooLargeException`, `MaxNestingDepthExceededException`, `TooManyCallsException`, `TooManyComponentsException`, or Shield's own guard on Livewire 3 | `413` | no |
| `stale_component` | `ComponentNotFoundException`, `LivewireReleaseTokenMismatchException` (page rendered by an older deployment) | `419` | no |

For each of these, Shield:

- answers with an empty body and an `X-Shield-Incident` header, and never with a stack trace, SQL, file path or exception message;
- writes one `livewire.<category>` warning instead of reporting an exception, so there is no double logging;
- counts tampering per address. After `max_attempts` within `decay_seconds`, every further Livewire request from that address gets `429` with `Retry-After`, before the payload is handled.

Everything else is left exactly as Laravel and Livewire handle it, with its normal status and normal reporting. That covers validation errors, `AuthenticationException`, `AuthorizationException`, CSRF (`419`), missing models (`404`), throttling and any real bug.

With `APP_DEBUG=true` the original response (the error page) is kept, so you can debug. Logging and counting still happen.

## What Shield does not do

- **No authorization.** Public properties, action parameters and `wire:model` values are untrusted input, so authorize in every action. `#[Locked]` only stops the client from changing a value. It does not decide who may see or change the record behind it.
- **No upstream patches.** Security fixes come from updating Livewire and Laravel. Run `composer audit` regularly.
- **No CSRF or session changes.** Shield never disables or bypasses them.
- **No security headers, CSP or CORS.** Configure those in your application.
- **No malware scanning** of uploads.
- **No kernel, web server, PHP or infrastructure fixes.**

### Authorization examples

```php
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EditInvoice extends Component
{
    #[Locked]
    public int $invoiceId;

    public function mount(Invoice $invoice): void
    {
        $this->authorize('update', $invoice);   // policy
        $this->invoiceId = $invoice->id;
    }

    public function save(): void
    {
        // Re-check on every request: the locked id stops tampering, not access.
        $invoice = Invoice::query()
            ->whereBelongsTo(auth()->user()->team)  // tenant scoping
            ->findOrFail($this->invoiceId);

        $this->authorize('update', $invoice);

        // ...
    }

    public function delete(int $lineId): void
    {
        // Action parameters come straight from the client.
        $line = Invoice::findOrFail($this->invoiceId)->lines()->findOrFail($lineId);
        Gate::authorize('delete', $line);
        $line->delete();
    }
}
```

### Uploads

Shield only blocks upload calls on components without `WithFileUploads`. The rest is up to your code:

- Validate type and size in the component, e.g. `#[Validate('file|mimes:pdf,png|max:5120')]`, and set `livewire.temporary_file_upload.rules`.
- Store files with a generated name, e.g. `$file->store('invoices', 'private')`, never under `$file->getClientOriginalName()`.
- Keep stored uploads out of `public/` and out of any directory the web server executes.
- SVG can carry script. Don't serve user SVGs inline from your own origin, or sanitize them. MIME checks don't detect polyglot files.
- Add a virus scanner yourself if you need one. Shield does not include one.

## Configuration

`config/livewire-shield.php`:

| Key | Default | Description |
| --- | --- | --- |
| `max_attempts` | `5` | Tampering attempts per address within `decay_seconds` before `429`. |
| `decay_seconds` | `900` | Window for attempts and for the log budget. |
| `middleware_group` | `'web'` | Group the middleware joins. It must be the group your Livewire update route uses. |
| `log_channel` | `null` | Log channel; `null` is the default channel. |
| `categories.<name>.status` | `403` / `413` / `419` | Production status per category. |
| `categories.<name>.report` | `false` | `true` also passes the exception to your reporter (Sentry, Flare, ...). Exceptions whose own `report()` returns true in production are not passed on by Laravel either way. |
| `payload.enabled` | `'auto'` | `'auto'` enforces the guard only when Livewire has no `livewire.payload` limits (Livewire 3); `true` always; `false` never. |
| `payload.max_bytes` / `max_components` / `max_calls` / `max_updates` | `1 MB` / `200` / `50` / `500` | Limits per request, counted on the decoded structure. `null` disables one. |
| `rate_limit.enabled` | `false` | Limit on all Livewire update traffic. |
| `rate_limit.per_minute_guest` / `per_minute_user` | `120` / `300` | Per address for guests, per account for signed-in users. |
| `rate_limit.tenant_resolver` | `null` | Class implementing `MonkeySoft\LivewireShield\Contracts\TenantResolver`, to scope limits per tenant. |
| `logging.enabled` | `true` | Write incident lines. |
| `logging.include_ip` | `true` | Include the client address. |
| `logging.max_lines_per_address` | `20` | Lines per address per `decay_seconds`; incidents beyond this are still counted. |

### Limits on the web server and PHP

Shield's payload guard only sees requests that PHP has already accepted, so set a hard limit in front of it as well:

- `client_max_body_size` (nginx), `LimitRequestBody` (Apache);
- `post_max_size` and `upload_max_filesize` (PHP);
- Livewire's own `livewire.payload` (Livewire 4).

### Rate limiting notes

- Polling (`wire:poll`) and `wire:model.live` send many requests. Measure your traffic before enabling `rate_limit`.
- Several users behind one NAT share an address. Signed-in users are limited per account, not per address.
- The address comes from `$request->ip()`. Forwarded headers count only when your `TrustProxies` configuration trusts the proxy.
- With more than one app server, use a shared cache (`redis`, `memcached`, `database`). `array` and per-server `file` caches don't share counters.
- Keys are hashes of the tenant, account or address, so request input cannot create unbounded keys.

## Frontend (optional)

By default Livewire shows an empty modal for a `403`/`413`, and a browser `confirm()` for a `419`. Add the directive to your layout, after `@livewireScripts` (or wherever Livewire is loaded), to show a translated plain-text notice instead:

```blade
@livewireShieldScripts
```

- It works with Livewire 3 (`Livewire.hook('request')`) and Livewire 4 (`Livewire.interceptRequest`), and registers once, also with `wire:navigate`.
- Messages are inserted as text, never as HTML. The CSP nonce from `Vite::useCspNonce()` is applied.
- Nothing is retried and the page is never reloaded on its own. On `419` the user gets a "Reload page" button, so unsaved input is never lost without a choice.
- In debug, only responses with an empty body are taken over, so Laravel's error page still shows.

To route notices to your own toast or notification library:

```js
window.LivewireShield = {
    notify(message, { status, incidentId, reload }) {
        myToast.error(message)
    },
}
```

Messages ship in English and Dutch (`livewire-shield::messages.*`). Publish the language files to change them.

## Log format

```
livewire.tampering {"ip":"203.0.113.7","category":"tampering","incident_id":"01JD…","exception":"Livewire\\Features\\SupportLockedProperties\\CannotUpdateLockedPropertyException","status":403,"user_id":42,"path":"livewire/update","component":"edit-invoice","property":"invoiceId"}
livewire.lockout {"ip":"203.0.113.7","user_id":42,"decay_seconds":900}
```

- The incident id is generated on the server and matches the `X-Shield-Incident` response header.
- Never logged: the request body, the snapshot, property values, upload content, cookies, tokens, session ids and exception messages.
- `component`, `property` and `path` are reduced to `[A-Za-z0-9_.:/-]`, at most 100 characters, so a log line can't be forged.

## Doctor

```bash
php artisan livewire-shield:doctor
```

The doctor is read-only and makes no network calls. It checks:

- your Livewire and Laravel versions against a dated list of minimum versions with known fixes;
- that the middleware is in its group;
- `APP_DEBUG` in production;
- whether the cache can hold counters;
- the temporary upload rules.

A clean run does not mean the installation is secure. Use `composer audit` for current advisories.

## Testing

```bash
composer install
composer test      # Pest
composer lint      # Pint
composer analyse   # Larastan
composer audit
```

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability privately.

## License

MIT. See [LICENSE](LICENSE).
