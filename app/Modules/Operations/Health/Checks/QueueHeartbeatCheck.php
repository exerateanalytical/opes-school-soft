<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduler writes this key every five minutes (routes/console.php).
 *
 * Without it a dead worker is invisible: nothing errors, nothing appears in a
 * log anyone reads, and the backups the scheduler runs simply stop happening.
 * The failure mode this exists to catch is silence.
 */
final class QueueHeartbeatCheck implements HealthCheck
{
    public const CACHE_KEY = 'opes.queue.heartbeat';

    public function run(): HealthCheckResult
    {
        $beat = $this->lastBeat();

        $red = (int) config('opes.health.queue_heartbeat_red_minutes');
        $amber = (int) config('opes.health.queue_heartbeat_amber_minutes');

        if ($beat === null) {
            return new HealthCheckResult(
                key: 'queue.heartbeat',
                label: 'Background tasks',
                status: HealthStatus::Red,
                detail: 'The background task runner has never reported in.',
                remedy: 'Nothing scheduled is running, which includes the nightly backup. '
                    .'Ask whoever installed the system to start the OPES scheduler service '
                    .'(php artisan schedule:work). Take a backup by hand today: '
                    .'php artisan opes:backup:run',
            );
        }

        $minutes = (int) $beat->diffInMinutes(now());
        $age = $minutes <= 1 ? 'a minute' : "{$minutes} minutes";

        if ($minutes >= $red) {
            return new HealthCheckResult(
                key: 'queue.heartbeat',
                label: 'Background tasks',
                status: HealthStatus::Red,
                detail: "The background task runner last reported in {$age} ago.",
                remedy: 'The scheduler has stopped. Ask whoever installed the system to '
                    .'restart it, then take a backup by hand today: php artisan opes:backup:run',
            );
        }

        if ($minutes >= $amber) {
            return new HealthCheckResult(
                key: 'queue.heartbeat',
                label: 'Background tasks',
                status: HealthStatus::Amber,
                detail: "The background task runner last reported in {$age} ago, which is late.",
                remedy: 'Reload this page in ten minutes. If it has not cleared, the '
                    .'scheduler needs restarting - and the nightly backup depends on it.',
            );
        }

        return new HealthCheckResult(
            key: 'queue.heartbeat',
            label: 'Background tasks',
            status: HealthStatus::Ok,
            detail: "Running; last reported in {$age} ago.",
            remedy: '',
        );
    }

    private function lastBeat(): ?Carbon
    {
        $raw = Cache::get(self::CACHE_KEY);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
