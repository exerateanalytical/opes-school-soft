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
                label: (string) __('opes.health.queue_heartbeat.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.queue_heartbeat.never_detail'),
                remedy: (string) __('opes.health.queue_heartbeat.never_remedy'),
            );
        }

        $minutes = (int) $beat->diffInMinutes(now());
        $age = $minutes <= 1
            ? (string) __('opes.health.queue_heartbeat.age_minute')
            : (string) __('opes.health.queue_heartbeat.age_minutes', ['minutes' => $minutes]);

        if ($minutes >= $red) {
            return new HealthCheckResult(
                key: 'queue.heartbeat',
                label: (string) __('opes.health.queue_heartbeat.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.queue_heartbeat.red_detail', ['age' => $age]),
                remedy: (string) __('opes.health.queue_heartbeat.red_remedy'),
            );
        }

        if ($minutes >= $amber) {
            return new HealthCheckResult(
                key: 'queue.heartbeat',
                label: (string) __('opes.health.queue_heartbeat.label'),
                status: HealthStatus::Amber,
                detail: (string) __('opes.health.queue_heartbeat.amber_detail', ['age' => $age]),
                remedy: (string) __('opes.health.queue_heartbeat.amber_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'queue.heartbeat',
            label: (string) __('opes.health.queue_heartbeat.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.queue_heartbeat.ok_detail', ['age' => $age]),
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
