<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Accounting\Actions\VerifyLedgerIntegrity;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;

/**
 * Crosses into Accounting through its ACTION, which the module boundary
 * permits; reaching for Accounting\Models\JournalEntry would not
 * (00-core 6.2). Same shape as AuditChainCheck for the same reason.
 *
 * Red whenever the nightly invariant sweep (02-accounting §4.3, backstop
 * column) has anything to say - a single finding means the double-entry
 * guarantees the rest of the system leans on can no longer be assumed.
 */
final class LedgerIntegrityCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $report = app(VerifyLedgerIntegrity::class)->handle();

        $total = 0;
        $broken = [];

        foreach ($report as $invariant => $findings) {
            if ($findings === []) {
                continue;
            }

            $total += count($findings);
            $broken[] = $invariant;
        }

        if ($total === 0) {
            return new HealthCheckResult(
                key: 'ledger.integrity',
                label: (string) __('opes.health.ledger_integrity.label'),
                status: HealthStatus::Ok,
                detail: (string) __('opes.health.ledger_integrity.ok_detail'),
                remedy: '',
            );
        }

        return new HealthCheckResult(
            key: 'ledger.integrity',
            label: (string) __('opes.health.ledger_integrity.label'),
            status: HealthStatus::Red,
            detail: (string) __('opes.health.ledger_integrity.red_detail', [
                'count' => $total,
                'invariants' => implode(', ', $broken),
            ]),
            remedy: (string) __('opes.health.ledger_integrity.red_remedy'),
        );
    }
}
