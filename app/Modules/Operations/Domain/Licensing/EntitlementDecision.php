<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Licensing;

/**
 * The answer AssertEntitlement gives to the four gated Actions
 * (CreateAcademicYear, PublishPeriod, StartRolloverRun,
 * BulkGenerateDocuments). Everything else - fee collection, receipts,
 * attendance, marks, payroll, the ledger, every export - never asks
 * (docs/specs/08-operations.md §4.4: "hiding a menu item is not
 * enforcement", and blocking a cashier queue is not either).
 */
enum EntitlementDecision: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';

    /**
     * The §4.4 table, verbatim: only `enforced` and `revoked` block, and
     * only the four annual/termly operations that call the gate.
     */
    public static function forState(LicenceState $state): self
    {
        return match ($state) {
            LicenceState::Valid,
            LicenceState::Trial,
            LicenceState::Expiring,
            LicenceState::Grace => self::Allowed,
            LicenceState::Enforced,
            LicenceState::Revoked => self::Blocked,
        };
    }

    public function allows(): bool
    {
        return $this === self::Allowed;
    }
}
