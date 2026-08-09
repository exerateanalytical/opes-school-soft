<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DomainException;

/**
 * docs/specs/05-hr-payroll.md 11.4: the byte-level DIPE layout
 * (cnps.cm/images/pdf/dipe.pdf) is NEEDS VERIFICATION. The export stays
 * disabled - with this explicit message, never a fabricated layout - until
 * an operator populates and activates the DipeLayout definition.
 */
final class DipeLayoutUnconfigured extends DomainException {}
