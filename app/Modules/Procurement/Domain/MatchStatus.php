<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.4/§4.5 - the match state stored on
 * BOTH the invoice header and each line (the exception report names the
 * line, not the invoice).
 */
enum MatchStatus: string
{
    case NotRequired = 'not_required';
    case Matched = 'matched';
    case Exception = 'exception';
    case Overridden = 'overridden';
}
