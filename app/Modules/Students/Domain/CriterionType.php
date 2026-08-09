<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md §10.4 — the seven criterion sources.
 *
 * Every value names WHERE its number comes from, and each source is a
 * cross-module door, never a re-derivation: v1's promotion engine had its own
 * mean, so the bulletin printed 13.13 and the promotion list said 13.28 for
 * the same student in the same run.
 */
enum CriterionType: string
{
    /** THE annual-average service the report card uses (01-assessment). */
    case AnnualAverage = 'annual_average';

    /** Per-subject annual average vs a floor, for a NAMED subject. */
    case SubjectMinimum = 'subject_minimum';

    /** §9.6 formula via the Attendance door. NULL rate ⇒ indeterminate, never a pass. */
    case AttendanceRate = 'attendance_rate';

    /** §9.7 heures d'absence non justifiées, from attendance_summaries. */
    case UnjustifiedAbsenceHours = 'unjustified_absence_hours';

    /** Count of DisciplineCase in the year, via the Welfare door. */
    case Discipline = 'discipline';

    /** 04-fees balance. ADVISORY by default — is_blocking needs the written-warning setting. */
    case FeeClearance = 'fee_clearance';

    /** Where the framework requires a conseil, it OVERRIDES every computed criterion. */
    case ConseilDecision = 'conseil_decision';

    public function requiresSubject(): bool
    {
        return $this === self::SubjectMinimum;
    }

    public function label(): string
    {
        return match ($this) {
            self::AnnualAverage => 'Annual average',
            self::SubjectMinimum => 'Subject minimum',
            self::AttendanceRate => 'Attendance rate',
            self::UnjustifiedAbsenceHours => 'Unjustified absence hours',
            self::Discipline => 'Discipline cases',
            self::FeeClearance => 'Fee clearance',
            self::ConseilDecision => 'Conseil decision',
        };
    }
}
