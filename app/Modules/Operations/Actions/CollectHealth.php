<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\Checks;
use App\Modules\Operations\Health\HealthCheck;
use Throwable;

final class CollectHealth
{
    /** @var list<class-string<HealthCheck>> */
    public const CHECKS = [
        Checks\AppVersionCheck::class,
        Checks\DatabaseCheck::class,
        Checks\MigrationsCheck::class,
        Checks\MysqlDurabilityCheck::class,
        Checks\BackupRecencyCheck::class,
        Checks\BackupSecondTargetCheck::class,
        Checks\RestoreDrillCheck::class,
        Checks\AuditChainCheck::class,
        Checks\LedgerIntegrityCheck::class,
        Checks\DiskSpaceCheck::class,
        Checks\QueueHeartbeatCheck::class,
        Checks\FailedJobsCheck::class,
    ];

    /**
     * @return list<HealthCheckResult>
     */
    public function handle(): array
    {
        $results = [];

        foreach (self::CHECKS as $class) {
            // Each check is individually guarded: an unreadable folder or a
            // dead connection must not blank the whole page, which is exactly
            // when the operator needs to read it.
            try {
                $results[] = app($class)->run();
            } catch (Throwable $e) {
                $results[] = new HealthCheckResult(
                    key: 'check.error',
                    label: class_basename($class),
                    status: HealthStatus::Red,
                    detail: 'This check could not run: '.$e->getMessage(),
                    remedy: 'Send the diagnostics bundle to support.',
                );
            }
        }

        return $results;
    }

    public function summary(): HealthStatus
    {
        return HealthStatus::worst(
            ...array_map(static fn (HealthCheckResult $r): HealthStatus => $r->status, $this->handle())
        );
    }
}
