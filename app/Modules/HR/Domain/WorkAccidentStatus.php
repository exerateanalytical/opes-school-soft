<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 11.5. The CNPS declaration deadline is NEEDS
 * VERIFICATION; `declared` requires a recorded declared_to_cnps_at (CHECK).
 */
enum WorkAccidentStatus: string
{
    case Open = 'open';
    case Declared = 'declared';
    case Closed = 'closed';
}
