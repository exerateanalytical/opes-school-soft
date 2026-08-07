<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use App\Modules\Operations\Models\Backup;

/**
 * How long ago the last HEALTHY backup completed. A failed or corrupt backup
 * does not count, because it cannot be restored from.
 */
final class BackupRecencyCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        /** @var Backup|null $latest */
        $latest = Backup::query()->healthy()->orderByDesc('completed_at')->first();

        if ($latest === null || $latest->completed_at === null) {
            return new HealthCheckResult(
                key: 'backup.recency',
                label: 'Last backup',
                status: HealthStatus::Red,
                detail: 'No backup has ever completed successfully.',
                remedy: 'Run: php artisan opes:backup:run. Until that succeeds, a disk '
                    .'failure this afternoon would lose every record the school has.',
            );
        }

        $hours = (int) $latest->completed_at->diffInHours(now());
        $red = (int) config('opes.health.backup_red_hours');
        $amber = (int) config('opes.health.backup_amber_hours');
        $age = $this->humanise($hours);

        if ($hours >= $red) {
            return new HealthCheckResult(
                key: 'backup.recency',
                label: 'Last backup',
                status: HealthStatus::Red,
                detail: "The last good backup finished {$age} ago.",
                remedy: 'Run: php artisan opes:backup:run. Then check that the nightly '
                    .'schedule is still running, because it should have taken this backup '
                    .'for you and did not.',
            );
        }

        if ($hours >= $amber) {
            return new HealthCheckResult(
                key: 'backup.recency',
                label: 'Last backup',
                status: HealthStatus::Amber,
                detail: "The last good backup finished {$age} ago, which is later than expected.",
                remedy: 'Run: php artisan opes:backup:run to bring it up to date, and tell '
                    .'whoever installed the system that last night\'s automatic backup was late.',
            );
        }

        return new HealthCheckResult(
            key: 'backup.recency',
            label: 'Last backup',
            status: HealthStatus::Ok,
            detail: "Completed {$age} ago and verified.",
            remedy: '',
        );
    }

    private function humanise(int $hours): string
    {
        if ($hours < 1) {
            return 'less than an hour';
        }

        if ($hours < 48) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        $days = intdiv($hours, 24);

        return "{$days} days";
    }
}
