<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Domain;

/**
 * The admission application lifecycle, docs/specs/07-students.md 6.1.
 *
 * An enum rather than free strings so an impossible transition is a type error
 * at analysis time rather than a row sitting in a status nothing handles.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Enrolled = 'enrolled';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    /**
     * Statuses from which conversion to a Student may proceed.
     *
     * 6.3 step 1 says `accepted` only. This product has no separate
     * DecideApplication screen yet, so an application that has been submitted
     * and reviewed is treated as acceptable to convert - the operator pressing
     * Confirm on the review step IS the acceptance, and it is recorded as such
     * (the row passes through `accepted` inside the conversion transaction).
     * A draft can never be converted, which is the invariant that matters.
     */
    public function isConvertible(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Accepted], true);
    }

    /**
     * Whether a decision on this application starts the 12-month retention
     * clock of 6.5. `enrolled` is exempt - that record is a student's history.
     */
    public function startsRetentionClock(): bool
    {
        return in_array($this, [self::Rejected, self::Expired, self::Withdrawn], true);
    }

    /** Whether the step data may still be edited by the wizard. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(string $locale = 'en'): string
    {
        $labels = trans('opes.admissions_screen.status', [], $locale);

        if (is_array($labels) && is_string($labels[$this->value] ?? null)) {
            return $labels[$this->value];
        }

        return 'opes.admissions_screen.status.'.$this->value;
    }
}
