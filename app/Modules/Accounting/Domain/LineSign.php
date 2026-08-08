<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The side a PostingRuleLine posts to. docs/specs/02-accounting.md §11.1.
 */
enum LineSign: string
{
    case Debit = 'debit';

    case Credit = 'credit';

    /**
     * The side is taken from the evaluated amount: positive posts a debit,
     * negative posts a credit of the absolute value.
     */
    case Signed = 'signed';
}
