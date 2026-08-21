<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;

/**
 * A backup on the same disk as the database is not a backup: the disk that
 * fails takes both. 08-operations 3.5 requires a second physical target, and
 * requires its absence to be VISIBLE rather than buried in a config file.
 *
 * This check used to pass on any non-empty string, so it could be cleared by
 * pointing at a folder beside the database - which is the precise arrangement
 * it exists to warn about. It now compares volumes, so a same-disk "second
 * target" stays amber and says why.
 */
final class BackupSecondTargetCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $target = config('opes.backup.second_target');

        if (! is_string($target) || trim($target) === '') {
            return $this->amber('amber_detail', 'amber_remedy');
        }

        $primary = config('opes.backup.path');
        $targetVolume = $this->volumeOf($target);
        $primaryVolume = is_string($primary) ? $this->volumeOf($primary) : null;

        /*
         * Only warn when the two are PROVABLY the same volume. If either
         * volume cannot be determined - an unplugged drive, a UNC share, a
         * path style this host does not use - the honest answer is to accept
         * the configuration rather than invent a fault we cannot substantiate.
         */
        if ($targetVolume !== null && $targetVolume === $primaryVolume) {
            return $this->amber('same_disk_detail', 'same_disk_remedy');
        }

        return new HealthCheckResult(
            key: 'backup.second_target',
            label: (string) __('opes.health.backup_second_target.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.backup_second_target.ok_detail'),
            remedy: '',
        );
    }

    private function amber(string $detail, string $remedy): HealthCheckResult
    {
        return new HealthCheckResult(
            key: 'backup.second_target',
            label: (string) __('opes.health.backup_second_target.label'),
            status: HealthStatus::Amber,
            detail: (string) __('opes.health.backup_second_target.'.$detail),
            remedy: (string) __('opes.health.backup_second_target.'.$remedy),
        );
    }

    /**
     * An opaque token identifying the volume a path sits on, or null when that
     * cannot be established.
     *
     * Tokens are only ever compared with each other, never parsed or shown.
     */
    private function volumeOf(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        /*
         * A drive letter is resolved WITHOUT touching the filesystem, which
         * matters here: an offsite target is typically a drive that is only
         * plugged in on backup day, so requiring it to exist would report
         * "cannot tell" exactly when the arrangement is working as intended.
         */
        if (preg_match('/^([A-Za-z]):/', $path, $m) === 1) {
            return 'drive:'.strtoupper($m[1]);
        }

        // POSIX: the device of the deepest ancestor that exists, since the
        // target directory itself may not have been created yet.
        $probe = $path;

        while (! file_exists($probe)) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                return null;
            }

            $probe = $parent;
        }

        $stat = @stat($probe);

        if (! is_array($stat) || ! isset($stat['dev'])) {
            return null;
        }

        return 'dev:'.$stat['dev'];
    }
}
