<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §3.1. `overdue` is deliberately NOT a status - it is
 * derived per instalment, never stored.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
