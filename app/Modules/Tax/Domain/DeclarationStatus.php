<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §7.1 - the declaration lifecycle.
 * `amended` is terminal for the original once its amendment exists;
 * `cancelled` frees the (type, period) slot for regeneration IN PLACE
 * (the DB unique key stays strict, so the generator reuses the row).
 */
enum DeclarationStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case UnderReview = 'under_review';
    case Filed = 'filed';
    case Paid = 'paid';
    case Amended = 'amended';
    case Cancelled = 'cancelled';

    /** Statuses that make a period "already declared" (§7.2 step 1). */
    public function occupiesPeriod(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Statuses a filing can start from. */
    public function isFileable(): bool
    {
        return $this === self::Generated || $this === self::UnderReview;
    }

    /** Filed-or-later: the declaration reached the DGI. */
    public function isFiled(): bool
    {
        return in_array($this, [self::Filed, self::Paid, self::Amended], true);
    }

    public function label(string $locale = 'en'): string
    {
        return __('opes.declaration_status.'.$this->value, [], $locale);
    }
}
