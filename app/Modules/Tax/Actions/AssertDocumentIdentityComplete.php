<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Models\FiscalIdentity;
use DomainException;

/**
 * docs/specs/03-tax-procurement.md §2.2 invariant 5 - the HARD GATE every
 * print path (invoice, receipt, credit note, supplier order, attestation,
 * statement) calls before rendering: an unidentified invoice is a legally
 * deficient document, and the school - not the vendor - bears the penalty.
 *
 * This is the cross-module door (Fees and Procurement call it); it is a
 * gate, not a warning, and it is deliberately NOT permission-checked - the
 * refusal protects the school regardless of who is printing.
 */
final class AssertDocumentIdentityComplete
{
    /**
     * @return FiscalIdentity the complete identity, for header rendering
     *
     * @throws DomainException naming the missing fields
     */
    public function handle(): FiscalIdentity
    {
        $identity = FiscalIdentity::current();

        if ($identity === null) {
            throw new DomainException(
                'No document may be printed: the fiscal identity is not configured at all '
                .'(niu, tax_regime, tax_centre_name, legal_name are required - 03-tax-procurement §2.2 invariant 5).'
            );
        }

        $missing = $identity->missingDocumentIdentityFields();

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'No document may be printed while the fiscal identity is incomplete; missing: %s '
                .'(03-tax-procurement §2.2 invariant 5).',
                implode(', ', $missing),
            ));
        }

        return $identity;
    }
}
