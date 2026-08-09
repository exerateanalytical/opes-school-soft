<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

use DomainException;

/**
 * Raised by AccrueMonthlyLeave while `leave_types.monthly_accrual_days` is
 * NULL (docs/specs/05-hr-payroll.md 12.3 + 0): the 1.5 j.o./month figure is
 * a 2.3 REFERENCE value, never seed data. The system refuses to accrue from
 * an unverified rate rather than silently accruing a guessed one.
 */
final class LeaveAccrualRateUnconfigured extends DomainException {}
