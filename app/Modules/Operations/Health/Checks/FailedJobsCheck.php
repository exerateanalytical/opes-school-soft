<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Support\Facades\DB;

/**
 * A failed job is a piece of work the school asked for and did not get: a
 * receipt not printed, a report not built. One is worth a warning.
 */
final class FailedJobsCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $count = (int) DB::table('failed_jobs')->count();

        $red = (int) config('opes.health.failed_jobs_red');
        $amber = (int) config('opes.health.failed_jobs_amber');

        $detail = $count === 1
            ? 'One background task has failed and was not retried.'
            : "{$count} background tasks have failed and were not retried.";

        if ($count >= $red) {
            return new HealthCheckResult(
                key: 'queue.failed_jobs',
                label: 'Failed tasks',
                status: HealthStatus::Red,
                detail: $detail,
                remedy: 'Something is failing repeatedly rather than once. Send the '
                    .'diagnostics bundle to support before clearing the list, then run: '
                    .'php artisan queue:retry all',
            );
        }

        if ($count >= $amber) {
            return new HealthCheckResult(
                key: 'queue.failed_jobs',
                label: 'Failed tasks',
                status: HealthStatus::Amber,
                detail: $detail,
                remedy: 'Run: php artisan queue:retry all. If the same task fails again, '
                    .'send the diagnostics bundle to support rather than retrying a third time.',
            );
        }

        return new HealthCheckResult(
            key: 'queue.failed_jobs',
            label: 'Failed tasks',
            status: HealthStatus::Ok,
            detail: 'No failed background tasks.',
            remedy: '',
        );
    }
}
