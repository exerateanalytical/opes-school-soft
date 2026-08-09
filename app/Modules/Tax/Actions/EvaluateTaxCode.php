<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Domain\TaxRegime;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxCode;
use DomainException;

/**
 * docs/specs/03-tax-procurement.md §5.2 / §2.2 invariants 3-4 - may this
 * tax code lawfully apply to a transaction on this date?
 *
 * - An EXEMPT code conditioned on ministry accreditation refuses when the
 *   accreditation is missing or expired at the transaction date: the
 *   exemption is a conditional privilege, not a property of the fee item.
 *   Silently invoicing exempt is exactly the finding a contrôle looks for.
 * - A TVA-bearing (non-exempt, non-zero) code refuses unless the school is
 *   TVA-registered under the régime réel with the registration effective
 *   at the date (§2.2 inv. 2-3).
 */
final class EvaluateTaxCode
{
    public function handle(TaxCode $taxCode, string $date): TaxCode
    {
        $identity = FiscalIdentity::current();

        if ($taxCode->is_exempt) {
            if ($taxCode->exemption_condition === 'ministry_accreditation') {
                if ($identity === null || ! $identity->hasValidAccreditationOn($date)) {
                    throw new DomainException(sprintf(
                        'Tax code %s claims the accreditation-conditional exemption, but the ministry '
                        .'accreditation is missing or expired on %s (03-tax-procurement §5.2). '
                        .'The line cannot be invoiced exempt.',
                        $taxCode->code,
                        $date,
                    ));
                }
            }

            return $taxCode;
        }

        if ($taxCode->rate_bp > 0 || $taxCode->is_zero_rated) {
            // §2.2 inv. 3: any TVA-bearing operation refuses unless the
            // school is an assujetti with the regime effective at the date.
            if ($identity === null || ! $identity->is_tva_registered) {
                throw new DomainException(sprintf(
                    'Tax code %s bears TVA, but the school is not TVA-registered '
                    .'(03-tax-procurement §2.2 invariant 3).',
                    $taxCode->code,
                ));
            }

            if ($identity->tax_regime !== TaxRegime::Reel
                || $identity->tax_regime_effective_from === null
                || $identity->tax_regime_effective_from->toDateString() > $date) {
                throw new DomainException(sprintf(
                    'Tax code %s bears TVA, but no régime réel is effective on %s '
                    .'(03-tax-procurement §2.2 invariants 2-3).',
                    $taxCode->code,
                    $date,
                ));
            }
        }

        return $taxCode;
    }
}
