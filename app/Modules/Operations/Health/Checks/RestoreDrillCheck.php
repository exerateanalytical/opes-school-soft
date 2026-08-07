<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use App\Modules\Operations\Models\RestoreDrill;

/**
 * A backup is not a backup until it has been restored. This reports how long
 * ago that was last PROVEN (08-operations 3.6).
 */
final class RestoreDrillCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        /** @var RestoreDrill|null $latest */
        $latest = RestoreDrill::query()
            ->where('status', 'passed')
            ->orderByDesc('completed_at')
            ->first();

        if ($latest === null || $latest->completed_at === null) {
            return new HealthCheckResult(
                key: 'drill.recency',
                label: 'Restore drill',
                status: HealthStatus::Red,
                detail: 'No backup has ever been restored successfully, so no backup is '
                    .'yet known to work.',
                remedy: 'Run: php artisan opes:backup:drill',
            );
        }

        $days = (int) $latest->completed_at->diffInDays(now());
        $red = (int) config('opes.health.drill_red_days');
        $amber = (int) config('opes.health.drill_amber_days');
        $age = $days === 1 ? '1 day' : "{$days} days";

        if ($days >= $red) {
            return new HealthCheckResult(
                key: 'drill.recency',
                label: 'Restore drill',
                status: HealthStatus::Red,
                detail: "The last successful restore drill was {$age} ago.",
                remedy: 'Run: php artisan opes:backup:drill',
            );
        }

        if ($days >= $amber) {
            return new HealthCheckResult(
                key: 'drill.recency',
                label: 'Restore drill',
                status: HealthStatus::Amber,
                detail: "The last successful restore drill was {$age} ago, and one is due.",
                remedy: 'Run: php artisan opes:backup:drill',
            );
        }

        return new HealthCheckResult(
            key: 'drill.recency',
            label: 'Restore drill',
            status: HealthStatus::Ok,
            detail: "A backup was restored and checked {$age} ago.",
            remedy: '',
        );
    }
}
