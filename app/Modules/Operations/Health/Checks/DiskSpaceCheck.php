<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use RuntimeException;

/**
 * Free space on the volume the backups are written to.
 *
 * The number that matters is the one on the BACKUP volume, not the application
 * volume: a full disk does not stop the school working, it stops the backup
 * being written, silently, for weeks.
 *
 * No absolute path appears in the output. /up is unauthenticated, and the
 * layout of the server's filesystem is not a monitor's business.
 */
final class DiskSpaceCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $volume = $this->volumeFor((string) config('opes.backup.path'));

        $free = disk_free_space($volume);
        $total = disk_total_space($volume);

        if ($free === false || $total === false || $total <= 0) {
            throw new RuntimeException('The free space on the backup volume could not be read.');
        }

        // intval before the percentage: these are byte counts in the terabytes
        // and only the ratio is wanted, so precision beyond a percent is noise.
        $usedPercent = (int) floor((($total - $free) / $total) * 100);
        $freeGb = number_format($free / (1024 ** 3), 1);

        $red = (int) config('opes.health.disk_red_percent');
        $amber = (int) config('opes.health.disk_amber_percent');

        $detail = (string) __('opes.health.disk_space.detail', ['percent' => $usedPercent, 'free' => $freeGb]);

        if ($usedPercent >= $red) {
            return new HealthCheckResult(
                key: 'disk.free',
                label: (string) __('opes.health.disk_space.label'),
                status: HealthStatus::Red,
                detail: $detail,
                remedy: (string) __('opes.health.disk_space.red_remedy'),
            );
        }

        if ($usedPercent >= $amber) {
            return new HealthCheckResult(
                key: 'disk.free',
                label: (string) __('opes.health.disk_space.label'),
                status: HealthStatus::Amber,
                detail: $detail,
                remedy: (string) __('opes.health.disk_space.amber_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'disk.free',
            label: (string) __('opes.health.disk_space.label'),
            status: HealthStatus::Ok,
            detail: $detail,
            remedy: '',
        );
    }

    /**
     * The configured backup folder may not exist yet (nothing has been backed
     * up on a fresh install), so walk up to the nearest ancestor that does.
     * That is still on the same volume, which is what is being measured.
     *
     * The walk refuses to end at a relative path. PHP's filesystem functions
     * treat a path containing a null byte as simply "not a directory" rather
     * than raising, so an unusable OPES_BACKUP_PATH would otherwise walk all
     * the way up to "." and cheerfully report free space on the CURRENT
     * WORKING DIRECTORY'S volume - a green light for a drive the backups are
     * not being written to, which is the exact failure this check exists to
     * prevent. Better to fail loudly and let CollectHealth show the reason.
     */
    private function volumeFor(string $path): string
    {
        $candidate = $path;

        while ($candidate !== '' && ! is_dir($candidate)) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                break;
            }

            $candidate = $parent;
        }

        if ($candidate === '' || ! is_dir($candidate) || ! $this->isAbsolute($candidate)) {
            throw new RuntimeException(
                'The configured backup folder is not a usable location, so free space '
                .'cannot be measured. Check the backup path setting.'
            );
        }

        return $candidate;
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path) === 1;
    }
}
