# Laravel Livewire Shield

Turns tampered Livewire payloads into a quiet 403, a log line and a per-address limit.

When someone hand-edits a Livewire request (for example writing to a `#[Locked]` property), Livewire already refuses the write, but the exception bubbles up as a 500 and lands in your error tracker. Shield recognises these cases and:

- answers **403** with an empty body instead of a 500
- **logs** a `livewire.tampering` warning instead of reporting an exception
- **rate limits** the address: after too many attempts, further Livewire requests get a **429** before the payload is handled at all

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- Livewire 3 or 4

## Installation

```bash
composer require monkeysoftnl/laravel-livewire-shield
```

The service provider is auto-discovered. No further setup is needed.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=livewire-shield-config
```

## What counts as tampering

An exception is treated as tampering when it is one of:

- `CannotUpdateLockedPropertyException`
- `CorruptComponentPayloadException`
- `PublicPropertyNotFoundException`
- a `TypeError` thrown while Livewire was hydrating an incoming property update (`HandleComponents::updateProperties`)

A `TypeError` from anywhere else is a real bug and is reported as usual. Only requests carrying the `X-Livewire` header get the 403; all other exceptions are untouched.

## Configuration

`config/livewire-shield.php`:

| Key | Default | Description |
| --- | --- | --- |
| `max_attempts` | `5` | Tampering attempts allowed per address within `decay_seconds` before a 429 is returned. |
| `decay_seconds` | `900` | Window in seconds in which attempts are counted. |
| `middleware_group` | `'web'` | Middleware group the 429 pre-check is appended to. Detection and the 403 work regardless. |
| `log_channel` | `null` | Log channel for the `livewire.tampering` warning. `null` uses the default channel. |

## Log format

```
livewire.tampering {"ip":"203.0.113.7","user_id":42,"path":"livewire/update","exception":"Livewire\\Features\\SupportLockedProperties\\CannotUpdateLockedPropertyException","property":"userId"}
```

The payload is deliberately never logged: the tampered property may sit next to secrets.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
