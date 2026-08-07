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
 */
final class BackupSecondTargetCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $target = config('opes.backup.second_target');
        $configured = is_string($target) && trim($target) !== '';

        if (! $configured) {
            return new HealthCheckResult(
                key: 'backup.second_target',
                label: 'Backup target',
                status: HealthStatus::Amber,
                detail: 'Backups are written to one location only.',
                remedy: 'Set a second backup target, such as a USB drive rotated weekly. '
                    .'A backup on the same disk as the database is lost with the disk.',
            );
        }

        return new HealthCheckResult(
            key: 'backup.second_target',
            label: 'Backup target',
            status: HealthStatus::Ok,
            detail: 'A second backup location is configured.',
            remedy: '',
        );
    }
}
