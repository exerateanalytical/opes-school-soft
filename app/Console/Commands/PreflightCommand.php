<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PreflightCommand extends Command
{
    protected $signature = 'opes:preflight';

    protected $description = 'Verify this machine can run OPES SCHOOL. Refuses unsupported stacks.';

    /**
     * Extensions required by docs/specs/00-core.md and 08-operations.md.
     *
     * @var list<string>
     */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'intl',
        'bcmath',
        'zip',
        'gd',
        'exif',
        'fileinfo',
        'openssl',
        'curl',
        'sodium',
    ];

    /**
     * @return list<string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));
    }

    public static function hasArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /**
     * MySQL 8+ only. MariaDB is explicitly unsupported (00-core §4) because
     * the required utf8mb4_0900_* collations are MySQL-exclusive.
     *
     * MariaDB advertises itself in several shapes, including the legacy
     * "5.5.5-" prefix it prepends for old-client compatibility, so we reject
     * on the vendor string first and only then parse the version.
     */
    public static function isSupportedDatabase(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 8;
    }

    public function handle(): int
    {
        $this->line('OPES SCHOOL preflight');
        $this->line('');

        // Collected rather than accumulated with &&: every check must run and
        // print its line, so the operator sees the whole picture in one pass
        // rather than fixing one problem at a time.
        $results = [
            $this->checkPhpVersion(),
            $this->checkExtensions(),
            $this->checkArgon2id(),
            $this->checkDatabase(),
        ];

        $this->line('');

        if (in_array(false, $results, true)) {
            $this->error('Preflight FAILED. Fix the items marked FAIL above before continuing.');

            return self::FAILURE;
        }

        $this->info('Preflight passed.');

        return self::SUCCESS;
    }

    private function report(bool $passed, string $label, string $detail, string $remedy = ''): bool
    {
        if ($passed) {
            $this->line(sprintf('  <fg=green>PASS</> %-22s %s', $label, $detail));

            return true;
        }

        $this->line(sprintf('  <fg=red>FAIL</> %-22s %s', $label, $detail));

        if ($remedy !== '') {
            $this->line(sprintf('       %-22s %s', '', $remedy));
        }

        return false;
    }

    public const MINIMUM_PHP = '8.3.0';

    /**
     * version_compare against the runtime string rather than PHP_VERSION_ID.
     *
     * composer.json already requires ^8.3, so PHPStan constant-folds
     * `PHP_VERSION_ID >= 80300` to always-true and correctly reports the
     * comparison as dead. The check still earns its place: an install done
     * with --ignore-platform-reqs, or a machine with several PHP builds where
     * the wrong one is on PATH, both reach runtime unguarded. Comparing the
     * runtime version string keeps the guard real and the analyser honest.
     */
    private function checkPhpVersion(): bool
    {
        return $this->report(
            version_compare(PHP_VERSION, self::MINIMUM_PHP, '>='),
            'PHP version',
            PHP_VERSION,
            'PHP '.self::MINIMUM_PHP.' or newer is required. Use Laragon\'s '
            .'php-8.3.30-Win32-vs16-x64 build.',
        );
    }

    private function checkExtensions(): bool
    {
        $missing = self::missingExtensions();

        return $this->report(
            $missing === [],
            'PHP extensions',
            $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing),
            'Enable the missing extensions in php.ini and restart.',
        );
    }

    private function checkArgon2id(): bool
    {
        return $this->report(
            self::hasArgon2id(),
            'Argon2id hashing',
            self::hasArgon2id() ? 'available' : 'unavailable',
            'Argon2id is required for password hashing (00-core 9.4).',
        );
    }

    private function checkDatabase(): bool
    {
        try {
            /** @var object{version: string}|null $row */
            $row = DB::selectOne('select version() as version');

            if ($row === null) {
                return $this->report(false, 'Database', 'version() returned nothing', '');
            }

            $version = $row->version;
        } catch (Throwable $e) {
            return $this->report(
                false,
                'Database',
                'not reachable: '.$e->getMessage(),
                'Start MySQL 8.4.3 from Laragon and check .env credentials.',
            );
        }

        return $this->report(
            self::isSupportedDatabase($version),
            'Database engine',
            $version,
            'MySQL 8.0+ is required. MariaDB is NOT supported - the required '
            .'utf8mb4_0900_* collations are MySQL-exclusive. In Laragon, select '
            .'mysql-8.4.3-winx64, not mariadb-xampp.',
        );
    }
}
