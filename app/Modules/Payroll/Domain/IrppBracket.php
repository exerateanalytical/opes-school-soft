<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * One annual IRPP bracket (docs/specs/05-hr-payroll.md 6.3): lower bound
 * inclusive, upper bound EXCLUSIVE, NULL = open top band - the same band
 * convention as every StatutoryRate row. The bracket VALUES never live in
 * code (05 §0): they arrive from verified StatutoryRate rows via the
 * Actions layer, and preflight check 6 has already asserted the set is
 * contiguous, non-overlapping, starts at zero and ends open.
 */
final readonly class IrppBracket
{
    public function __construct(
        public int $lowerInclusive,
        public ?int $upperExclusive,
        public int $rateBp,
        public ?int $statutoryRateId = null,
    ) {
    }
}
