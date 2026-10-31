<?php

namespace MonkeySoft\LivewireShield\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use MonkeySoft\LivewireShield\Http\Middleware\BlockTamperedLivewireRequests;

/**
 * Read-only checks of the local installation. Makes no network calls: the version minimums
 * below are a snapshot of the advisories known on ADVISORY_DATE, so a clean run never means
 * the installation is secure; `composer audit` has the current data.
 */
class DoctorCommand extends Command
{
    public const ADVISORY_DATE = '2026-10-31';

    /**
     * Lowest version per major without a known advisory, as of ADVISORY_DATE.
     * See docs/security-review.md for the advisories behind each entry.
     *
     * @var array<string, array<int, string>>
     */
    public const MINIMUMS = [
        'livewire/livewire' => [3 => '3.8.3', 4 => '4.4.8'],
        'laravel/framework' => [12 => '12.69.0', 13 => '13.30.0'],
    ];

    protected $signature = 'livewire-shield:doctor';

    protected $description = 'Check the Livewire Shield installation for common problems (read-only)';

    private int $problems = 0;

    public function handle(Router $router): int
    {
        foreach (self::MINIMUMS as $package => $minimums) {
            $this->checkVersion($package, $minimums);
        }

        $group = (string) config('livewire-shield.middleware_group');
        $this->check(
            in_array(BlockTamperedLivewireRequests::class, $router->getMiddlewareGroups()[$group] ?? [], true),
            "Shield middleware is in the [{$group}] group.",
            "Shield middleware is missing from the [{$group}] group; set `livewire-shield.middleware_group` to the group your Livewire update route uses.",
        );

        $this->check(
            ! (config('app.debug') && $this->laravel->environment('production')),
            'APP_DEBUG is off in production.',
            'APP_DEBUG is on in production: error pages expose internals and Shield keeps the original responses.',
        );

        $store = (string) config('cache.default');
        $this->check(
            ! in_array(config("cache.stores.{$store}.driver"), ['array', 'null'], true),
            "Cache store [{$store}] persists rate limits.",
            "Cache store [{$store}] does not persist between requests, so attempt counting and rate limits have no effect.",
        );

        if (config("cache.stores.{$store}.driver") === 'file') {
            $this->warn('  ! The file cache is per server; use redis, memcached or database when running several app servers.');
        }

        $this->check(
            config('livewire.temporary_file_upload.rules') !== null,
            'Livewire temporary upload rules are configured.',
            'livewire.temporary_file_upload.rules is null: Livewire accepts any file type up to 12 MB as temporary upload. Validate type and size in your components too.',
        );

        $this->newLine();
        $this->line('Advisory data dated '.self::ADVISORY_DATE.'. Run `composer audit` for current advisories; this check does not prove the installation is secure.');

        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, string>  $minimums
     */
    private function checkVersion(string $package, array $minimums): void
    {
        if (! InstalledVersions::isInstalled($package)) {
            return;
        }

        $version = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
        $minimum = $minimums[(int) $version] ?? null;

        if ($minimum === null || ! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            $this->warn("  ! {$package} {$version}: no advisory minimum known for this version; check it with `composer audit`.");

            return;
        }

        $this->check(
            version_compare($version, $minimum, '>='),
            "{$package} {$version} is at or above {$minimum}.",
            "{$package} {$version} is below {$minimum}, which fixes known advisories. Update it.",
        );
    }

    private function check(bool $ok, string $pass, string $fail): void
    {
        if ($ok) {
            $this->info("  ✓ {$pass}");

            return;
        }

        $this->problems++;
        $this->error("  ✗ {$fail}");
    }
}
