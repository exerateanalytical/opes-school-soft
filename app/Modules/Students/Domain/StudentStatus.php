<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md 3.2.
 *
 * This is a DERIVED cache of the student's enrollment history, not an
 * independent lifecycle. v1 ran Student.status and Enrollment.status as two
 * overlapping state machines with no stated relationship, and they diverged
 * within a term. Only RecomputeStudentStatus (owned by the enrollment
 * workstream) writes it, through Student::applyDerivedStatus().
 *
 * `deceased` is the one value an operator sets directly, via deceased_on.
 */
enum StudentStatus: string
{
    case Prospective = 'prospective';
    case Active = 'active';
    case Inactive = 'inactive';
    case Graduated = 'graduated';
    case TransferredOut = 'transferred_out';
    case Withdrawn = 'withdrawn';
    case Deceased = 'deceased';

    /**
     * Whether the student is countable in the current-year effectif.
     *
     * Stated here rather than re-derived at each call site, because the KPI
     * cards, the class donut and the promotion run must all agree on it.
     */
    public function isCurrent(): bool
    {
        return $this === self::Active;
    }

    /**
     * No further enrollment is expected. `inactive` is deliberately NOT
     * terminal: a student with no enrollment in the current year may return
     * next year, which is common after a fee-driven interruption.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Graduated, self::TransferredOut, self::Withdrawn, self::Deceased => true,
            self::Prospective, self::Active, self::Inactive => false,
        };
    }
}
