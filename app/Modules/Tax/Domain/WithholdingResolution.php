<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §6.4 - the outcome of resolving
 * withholding for ONE document line. Amounts in whole FCFA; rate in
 * App\Support\Rate scale (100 000 bp = 100%).
 *
 * `ruleId === null` means no withholding, and `reason` says WHY - silence
 * is not an answer here (§6.4.7):
 *
 * - 'exempt_supplier':  supplier exemption unexpired at the date; the
 *                        exemption reference is recorded.
 * - 'below_threshold':  a rule matched but the base is under minimum_base.
 * - 'unresolved':       NO rule matched; the invoice must carry
 *                        withholding_unresolved = true and cannot be
 *                        approved without procurement.invoice.
 *                        waive_withholding and a stored reason.
 */
final readonly class WithholdingResolution
{
    public const REASON_EXEMPT_SUPPLIER = 'exempt_supplier';

    public const REASON_BELOW_THRESHOLD = 'below_threshold';

    public const REASON_UNRESOLVED = 'unresolved';

    public function __construct(
        public ?int $ruleId,
        public int $baseAmount,
        public int $rateBpApplied,
        public int $withheldAmount,
        public ?string $reason = null,
        public ?string $exemptionRef = null,
    ) {
    }

    public function isWithheld(): bool
    {
        return $this->ruleId !== null && $this->withheldAmount > 0;
    }

    public function isUnresolved(): bool
    {
        return $this->reason === self::REASON_UNRESOLVED;
    }
}
