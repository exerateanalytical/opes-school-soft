<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Models\TaxCode;
use DomainException;

/**
 * docs/specs/03-tax-procurement.md §5.3 - resolve the TaxCode VERSION in
 * force for a document line. Catalog defaults (FeeItem.tax_code_id,
 * AssetCategory...) point at SOME version of a code; the version that
 * governs a document is the one effective on the DOCUMENT date (invoice
 * date / issue date), never now() and never blindly the referenced row.
 *
 * Exactly one version must be in force: none is a configuration gap
 * (empty-seed refusal, §11.16), two is a configuration error the overlap
 * check should have prevented - both refuse loudly rather than pick.
 */
final class ResolveTaxCodeFor
{
    public function handle(int $taxCodeId, string $date): TaxCode
    {
        /** @var TaxCode $referenced */
        $referenced = TaxCode::query()->findOrFail($taxCodeId);

        /** @var \Illuminate\Database\Eloquent\Collection<int, TaxCode> $versions */
        $versions = TaxCode::query()
            ->where('code', $referenced->code)
            ->where('is_active', true)
            ->effectiveOn($date)
            ->get();

        if ($versions->isEmpty()) {
            throw new DomainException(sprintf(
                'No active version of tax code %s is in force on %s - configure one with your accountant '
                .'(03-tax-procurement §5.3).',
                $referenced->code,
                $date,
            ));
        }

        if ($versions->count() > 1) {
            throw new DomainException(sprintf(
                'Tax code %s has %d versions in force on %s - a configuration error; exactly one must apply (§5.3).',
                $referenced->code,
                $versions->count(),
                $date,
            ));
        }

        /** @var TaxCode $version */
        $version = $versions->first();

        return $version;
    }

    /**
     * Batch variant for line sets: one resolution per distinct tax code id.
     *
     * @param  list<int>  $taxCodeIds
     * @return array<int, TaxCode> keyed by the ORIGINAL tax code id
     */
    public function forLines(array $taxCodeIds, string $date): array
    {
        $resolved = [];

        foreach (array_values(array_unique($taxCodeIds)) as $taxCodeId) {
            $resolved[$taxCodeId] = $this->handle($taxCodeId, $date);
        }

        return $resolved;
    }
}
