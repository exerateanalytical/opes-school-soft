<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Licensing\EntitlementBlocked;
use App\Modules\Operations\Licensing\LicenceEvaluation;
use App\Modules\Operations\Licensing\LicenceStatus;

/**
 * THE entitlement gate - the one cross-module door of
 * docs/specs/08-operations.md §4.4, called at the top of exactly the four
 * annual/termly Actions the spec names (CreateAcademicYear, PublishPeriod,
 * the rollover wizard, bulk document generation). "Enforcement lives in
 * Actions, not the UI. Hiding a menu item is not enforcement."
 *
 * Equally binding is the NEGATIVE space: RecordPayment, attendance, marks
 * entry, payroll, the ledger and EVERY export never call this - blocking a
 * cashier queue at the school gate converts a billing conversation into a
 * reputational event, and data export is never blocked in any state,
 * including revoked. EntitlementGateTest greps the codebase to keep the
 * call-site list closed.
 *
 * Fully offline: the evaluation reads the cached licence row and verifies
 * it cryptographically. No permission check here on purpose - entitlement
 * is about the SCHOOL's licence, not the operator's rights, and the calling
 * Action has already authorized the operator.
 */
final class AssertEntitlement
{
    public function __construct(private readonly LicenceStatus $status)
    {
    }

    /**
     * @param  string  $operation  A lang-keyed operation slug, e.g.
     *                             'academics.create_year' - see
     *                             lang/{en,fr}/licence.php `operation.*`.
     *
     * @throws EntitlementBlocked when the licence state is enforced/revoked.
     */
    public function handle(string $operation): void
    {
        $evaluation = $this->evaluate();

        if ($evaluation->decision()->allows()) {
            return;
        }

        throw new EntitlementBlocked($evaluation->state, $operation);
    }

    /** The full evaluation, for banners and the health page. */
    public function evaluate(): LicenceEvaluation
    {
        return $this->status->evaluate();
    }
}
