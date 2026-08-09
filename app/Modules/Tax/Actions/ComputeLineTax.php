<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Domain\LineTax;
use App\Modules\Tax\Domain\ProrataBasis;
use App\Modules\Tax\Domain\TaxDirection;
use App\Modules\Tax\Models\VatProrata;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/03-tax-procurement.md §5.4 mechanics rule 2 - the ONE place
 * line tax and its deductible/non-deductible split are computed.
 *
 * Output side: tax only (collection is not limited by the prorata).
 * Input side: `deductible = round_half_up(tax × prorata)` and
 * `non_deductible = tax − deductible` - the SUBTRACTION, not a second
 * rounding, guarantees conservation to the franc. Getting this wrong
 * overstates deductible VAT by the exempt share of every mixed-use
 * purchase, which is precisely the finding a DGI contrôle looks for.
 *
 * Refusals, per the empty-seed discipline (§11.16):
 * - no active tax code version on the date (ResolveTaxCodeFor),
 * - identity/registration/exemption gates (EvaluateTaxCode),
 * - direction mismatch,
 * - input VAT with no CONFIRMED prorata for the fiscal year of the date -
 *   never a silent 100% deduction.
 */
final class ComputeLineTax
{
    public function __construct(
        private readonly ResolveTaxCodeFor $resolveTaxCode,
        private readonly EvaluateTaxCode $evaluateTaxCode,
    ) {
    }

    public function handle(int $amountHt, int $taxCodeId, string $date, TaxDirection $direction): LineTax
    {
        if ($direction === TaxDirection::Both) {
            throw new DomainException('A line computes tax on exactly one side: output or input.');
        }

        $taxCode = $this->resolveTaxCode->handle($taxCodeId, $date);
        $this->evaluateTaxCode->handle($taxCode, $date);

        if ($taxCode->direction !== 'both' && $taxCode->direction !== $direction->value) {
            throw new DomainException(sprintf(
                'Tax code %s is %s-side; it cannot apply to an %s line.',
                $taxCode->code,
                $taxCode->direction,
                $direction->value,
            ));
        }

        // Exempt (and zero-rated) lines carry no tax amount at all.
        $tax = $taxCode->is_exempt
            ? Money::zero()
            : $taxCode->rate()->applyTo(Money::of($amountHt));

        if ($direction === TaxDirection::Output || $tax->isZero()) {
            return new LineTax($tax->amount(), 0, 0);
        }

        $prorata = $this->confirmedProrataFor($date);

        $deductible = $prorata->rate()->applyTo($tax);
        $nonDeductible = $tax->minus($deductible);

        return new LineTax($tax->amount(), $deductible->amount(), $nonDeductible->amount());
    }

    /**
     * The confirmed prorata governing a document date: the fiscal year is
     * resolved by date (cross-module read via DB::table - FiscalYear is
     * Accounting's model), preferring the DEFINITIVE prorata once
     * confirmed, else the provisional one applied during the year (§5.4).
     */
    private function confirmedProrataFor(string $date): VatProrata
    {
        $fiscalYearId = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->value('id');

        if ($fiscalYearId === null) {
            throw new DomainException(sprintf(
                'No fiscal year covers %s; input VAT cannot be split without one (03-tax-procurement §5.4).',
                $date,
            ));
        }

        /** @var VatProrata|null $prorata */
        $prorata = VatProrata::query()
            ->where('fiscal_year_id', (int) $fiscalYearId)
            ->whereNotNull('confirmed_at')
            ->orderByRaw("FIELD(basis, ?, ?)", [
                ProrataBasis::Definitive->value,
                ProrataBasis::Provisional->value,
            ])
            ->first();

        if ($prorata === null) {
            throw new DomainException(
                'No CONFIRMED prorata de déduction exists for the fiscal year of this document; '
                .'compute and confirm one with your accountant before deducting input VAT '
                .'(03-tax-procurement §5.4, empty-seed refusal §11.16).'
            );
        }

        return $prorata;
    }
}
