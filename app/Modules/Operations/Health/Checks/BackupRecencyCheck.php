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
                label: (string) __('opes.health.backup_recency.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.backup_recency.never_detail'),
                remedy: (string) __('opes.health.backup_recency.never_remedy'),
            );
        }

        $hours = (int) $latest->completed_at->diffInHours(now());
        $red = (int) config('opes.health.backup_red_hours');
        $amber = (int) config('opes.health.backup_amber_hours');
        $age = $this->humanise($hours);

        if ($hours >= $red) {
            return new HealthCheckResult(
                key: 'backup.recency',
                label: (string) __('opes.health.backup_recency.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.backup_recency.red_detail', ['age' => $age]),
                remedy: (string) __('opes.health.backup_recency.red_remedy'),
            );
        }

        if ($hours >= $amber) {
            return new HealthCheckResult(
                key: 'backup.recency',
                label: (string) __('opes.health.backup_recency.label'),
                status: HealthStatus::Amber,
                detail: (string) __('opes.health.backup_recency.amber_detail', ['age' => $age]),
                remedy: (string) __('opes.health.backup_recency.amber_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'backup.recency',
            label: (string) __('opes.health.backup_recency.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.backup_recency.ok_detail', ['age' => $age]),
            remedy: '',
        );
    }

    private function humanise(int $hours): string
    {
        if ($hours < 1) {
            return (string) __('opes.health.backup_recency.age_less_than_hour');
        }

        if ($hours < 48) {
            return $hours === 1
                ? (string) __('opes.health.backup_recency.age_hour')
                : (string) __('opes.health.backup_recency.age_hours', ['hours' => $hours]);
        }

        $days = intdiv($hours, 24);

        return (string) __('opes.health.backup_recency.age_days', ['days' => $days]);
    }
}
