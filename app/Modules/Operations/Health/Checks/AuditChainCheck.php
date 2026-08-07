<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Identity\Actions\VerifyAuditChain;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;

/**
 * Crosses into Identity through its ACTION, which the module boundary permits;
 * reaching for Identity\Models\AuditLog would not (00-core 6.2).
 */
final class AuditChainCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $result = app(VerifyAuditChain::class)->handle();

        if ($result->isIntact()) {
            return new HealthCheckResult(
                key: 'audit.chain',
                label: 'Audit log',
                status: HealthStatus::Ok,
                detail: "Intact, {$result->checked} entries verified.",
                remedy: '',
            );
        }

        return new HealthCheckResult(
            key: 'audit.chain',
            label: 'Audit log',
            status: HealthStatus::Red,
            detail: 'The audit log has been changed outside the application: '
                .(string) $result->reason,
            remedy: 'Do not clear this yourself. The record of who did what has been '
                .'altered, which means someone had direct access to the database. Tell the '
                .'head of school today, keep the current backups, and contact support.',
        );
    }
}
