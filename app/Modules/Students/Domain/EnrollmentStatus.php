<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * Lifecycle of one Enrollment (docs/specs/07-students.md 3.2 / 3.3).
 *
 * Deliberately distinct from Student.status: v1 had two overlapping lifecycles
 * with no stated relationship and they diverged within a term. Here the
 * enrollment lifecycle is the FACT and Student.status is a derived cache of it
 * (DeriveStudentStatus).
 *
 * The three "live" cases are load-bearing: they are exactly the set the
 * `active_year_key` generated column keys on, which is what makes C1
 * ("no second live enrollment in one year") a database guarantee rather than
 * an application convention.
 */
enum EnrollmentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Withdrawn = 'withdrawn';
    case TransferredOut = 'transferred_out';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * The statuses that make an enrollment occupy its (student, year) slot.
     * Mirrors the CASE expression of `enrollments.active_year_key`.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Pending, self::Active, self::Suspended];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    /**
     * 3.3: withdrawn, transferred_out, completed and cancelled are terminal,
     * and 4.2 invariant 3 ties terminality to `left_on` being set.
     */
    public function isTerminal(): bool
    {
        return ! $this->isLive();
    }

    /**
     * 3.3 - the allowed transition graph. Anything outside it is rejected by
     * the Action, not merely by the UI.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Withdrawn, self::TransferredOut, self::Completed],
            self::Suspended => [self::Active, self::Withdrawn, self::TransferredOut],
            self::Withdrawn, self::TransferredOut, self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
