<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

use App\Support\Money\Money;

/**
 * docs/specs/03-tax-procurement.md §5.4 - the result of ComputeLineTax for
 * one document line, in whole FCFA.
 *
 * Conservation is structural: `nonDeductible` is derived by SUBTRACTION
 * from `taxAmount`, never by a second rounding, so
 * `deductible + nonDeductible === taxAmount` always holds on the INPUT
 * side (§5.4 mechanics rule 2). The constructor refuses a triple that
 * breaks it, so a caller cannot assemble a lossy split by hand.
 *
 * An OUTPUT line carries no split at all - collected TVA is not limited
 * by the prorata - so `deductible = nonDeductible = 0` beside a non-zero
 * tax is the one legal exception.
 */
final readonly class LineTax
{
    public function __construct(
        public int $taxAmount,
        public int $deductible,
        public int $nonDeductible,
    ) {
        $isOutputShape = $deductible === 0 && $nonDeductible === 0;

        if (! $isOutputShape && $deductible + $nonDeductible !== $taxAmount) {
            throw new \DomainException(sprintf(
                'LineTax must conserve the franc: %d deductible + %d non-deductible != %d tax.',
                $deductible,
                $nonDeductible,
                $taxAmount,
            ));
        }
    }

    public function taxAmount(): Money
    {
        return Money::of($this->taxAmount);
    }

    public function deductible(): Money
    {
        return Money::of($this->deductible);
    }

    public function nonDeductible(): Money
    {
        return Money::of($this->nonDeductible);
    }
}
