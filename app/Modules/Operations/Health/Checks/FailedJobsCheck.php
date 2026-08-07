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
            ? (string) __('opes.health.failed_jobs.detail_one')
            : (string) __('opes.health.failed_jobs.detail_many', ['count' => $count]);

        if ($count >= $red) {
            return new HealthCheckResult(
                key: 'queue.failed_jobs',
                label: (string) __('opes.health.failed_jobs.label'),
                status: HealthStatus::Red,
                detail: $detail,
                remedy: (string) __('opes.health.failed_jobs.red_remedy'),
            );
        }

        if ($count >= $amber) {
            return new HealthCheckResult(
                key: 'queue.failed_jobs',
                label: (string) __('opes.health.failed_jobs.label'),
                status: HealthStatus::Amber,
                detail: $detail,
                remedy: (string) __('opes.health.failed_jobs.amber_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'queue.failed_jobs',
            label: (string) __('opes.health.failed_jobs.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.failed_jobs.ok_detail'),
            remedy: '',
        );
    }
}
