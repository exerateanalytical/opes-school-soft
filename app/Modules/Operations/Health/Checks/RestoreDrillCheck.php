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
                label: (string) __('opes.health.restore_drill.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.restore_drill.never_detail'),
                remedy: (string) __('opes.health.restore_drill.run_remedy'),
            );
        }

        $days = (int) $latest->completed_at->diffInDays(now());
        $red = (int) config('opes.health.drill_red_days');
        $amber = (int) config('opes.health.drill_amber_days');
        $age = match (true) {
            $days < 1 => (string) __('opes.health.restore_drill.age_today'),
            $days === 1 => (string) __('opes.health.restore_drill.age_one_day'),
            default => (string) __('opes.health.restore_drill.age_days', ['days' => $days]),
        };

        if ($days >= $red) {
            return new HealthCheckResult(
                key: 'drill.recency',
                label: (string) __('opes.health.restore_drill.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.restore_drill.red_detail', ['age' => $age]),
                remedy: (string) __('opes.health.restore_drill.run_remedy'),
            );
        }

        if ($days >= $amber) {
            return new HealthCheckResult(
                key: 'drill.recency',
                label: (string) __('opes.health.restore_drill.label'),
                status: HealthStatus::Amber,
                detail: (string) __('opes.health.restore_drill.amber_detail', ['age' => $age]),
                remedy: (string) __('opes.health.restore_drill.run_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'drill.recency',
            label: (string) __('opes.health.restore_drill.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.restore_drill.ok_detail', ['age' => $age]),
            remedy: '',
        );
    }
}
