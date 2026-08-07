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
                label: (string) __('opes.health.audit_chain.label'),
                status: HealthStatus::Ok,
                detail: (string) __('opes.health.audit_chain.ok_detail', ['count' => $result->checked]),
                remedy: '',
            );
        }

        return new HealthCheckResult(
            key: 'audit.chain',
            label: (string) __('opes.health.audit_chain.label'),
            status: HealthStatus::Red,
            detail: (string) __('opes.health.audit_chain.red_detail', ['reason' => (string) $result->reason]),
            remedy: (string) __('opes.health.audit_chain.red_remedy'),
        );
    }
}
