<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §16, "Phasing":
 *
 *   "A school's spending is violently seasonal — September and January are
 *   not one-twelfth each."
 *
 * `Equal` spreads the annual figure across every period of the fiscal year.
 * `AcademicCalendar` weights it toward the term starts. `Manual` takes the
 * operator's own per-period figures verbatim.
 *
 * The weights below are RATIOS handed to `Money::allocate`, never percentages
 * applied one period at a time: allocate distributes the remainder so the set
 * sums back to the annual amount exactly (B-1), which independent rounding
 * per period does not.
 */
enum PhasingProfile: string
{
    case Equal = 'equal';
    case AcademicCalendar = 'academic_calendar';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Equal => 'Equal (one-twelfth per month)',
            self::AcademicCalendar => 'Academic calendar (weighted to term starts)',
            self::Manual => 'Manual (per-period amounts)',
        };
    }

    /**
     * Weight for a calendar month, 1 = January. The Cameroonian school year
     * runs September to June with terms opening in September, January and
     * April, so those three months carry the heavy weight and the July/August
     * vacation carries the light one.
     */
    public function weightForMonth(int $month): int
    {
        if ($this === self::Equal) {
            return 1;
        }

        return match ($month) {
            9, 1 => 4,      // Rentrée and the second-term opening.
            4 => 3,         // Third term opens.
            10, 11, 2, 3, 5, 6 => 2,
            12 => 1,        // Half a month of teaching before the break.
            default => 1,   // July, August - vacation.
        };
    }
}
