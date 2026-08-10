<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * `budgets.status` (docs/specs/02-accounting.md §16).
 *
 * `draft` is the only editable state (B-2): once approved, a change produces
 * `version + 1` rather than mutating the figures somebody signed off.
 */
enum BudgetStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Closed = 'closed';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Closed => 'Closed',
        };
    }
}
