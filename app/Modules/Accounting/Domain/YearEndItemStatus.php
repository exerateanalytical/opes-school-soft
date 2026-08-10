<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §17.3 - `YearEndChecklistItem.status`.
 *
 * `waived` is deliberately NOT a flavour of `completed`: §17.3 requires the
 * waiver list to be printed on the closing report, so "what did you skip?"
 * is one page. Collapsing the two would erase exactly that answer.
 */
enum YearEndItemStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Waived = 'waived';

    /** Satisfied for YE-1 purposes - done, or consciously skipped on record. */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
