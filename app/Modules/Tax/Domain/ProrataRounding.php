<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §5.4 / §12 item 8 - the prorata rounding
 * rule. Whether the CGI requires rounding up to the next whole percent (the
 * common francophone rule) NEEDS VERIFICATION, so TaxSettings ships it
 * unset and ComputeVatProrata refuses until the accountant decides.
 *
 * ExactBp rounds half-up to the basis point (0.01%): 11.7241% → 11.72%.
 * UpToWholePercent ceils: 11.7241% → 12%.
 */
enum ProrataRounding: string
{
    case ExactBp = 'exact_bp';
    case UpToWholePercent = 'up_to_whole_percent';
}
