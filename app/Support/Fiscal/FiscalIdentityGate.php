<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §4.7 / §10 - "the fiscal identity fields are
 * MANDATORY on Invoice, Receipt, Credit Note and Refund... a school without
 * a NIU cannot issue a legally sufficient receipt, and the render BLOCKS
 * with a setup prompt rather than printing a deficient document."
 *
 * Lives in app/Support/ rather than the Tax module because Fees and
 * Procurement's money-document Print Actions both need it and a module may
 * not import another module's Model (00-core §6.2) - `Tax\Models\
 * FiscalIdentity::missingDocumentIdentityFields()` already implements this
 * EXACT check for the Tax module's own screens, and this class deliberately
 * mirrors it field-for-field over a `DB::table` read instead, so the two
 * never drift apart while staying architecture-clean.
 */
final class FiscalIdentityGate
{
    /**
     * @return list<string> the missing field names, empty when complete
     */
    public static function missingFields(): array
    {
        /** @var object{niu: string|null, tax_regime: string|null, tax_centre_name: string|null, legal_name: string|null}|null $row */
        $row = DB::table('fiscal_identities')
            ->where('id', 1)
            ->first(['niu', 'tax_regime', 'tax_centre_name', 'legal_name']);

        $missing = [];

        foreach (['niu', 'tax_regime', 'tax_centre_name', 'legal_name'] as $field) {
            $value = $row?->{$field};

            if ($value === null || trim($value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Refuses with a setup prompt rather than printing a deficient money
     * document (10-documents §4.7). Called by the Print Action BEFORE
     * RenderDocument, so an incomplete fiscal identity never even reaches
     * the render/hash/series-allocation transaction.
     */
    public static function assertCompleteForMoneyDocuments(): void
    {
        $missing = self::missingFields();

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'The school fiscal identity is incomplete (missing: %s). Complete Settings -> Fiscal Identity '
                .'before printing a money document (03-tax-procurement §2, 10-documents §4.7).',
                implode(', ', $missing),
            ));
        }
    }
}
