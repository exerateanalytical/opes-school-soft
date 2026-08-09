<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §5.2 - how the FIRST period of depreciation is
 * measured. NEEDS VERIFICATION (V1) which one SYSCOHADA prescribes, so the
 * seeder leaves the category column NULL and the school's accountant must
 * declare a policy; the chosen value is snapshotted onto the asset at
 * capitalisation.
 */
enum ProrataConvention: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case FullMonth = 'full_month';
    case HalfYear = 'half_year';
}
