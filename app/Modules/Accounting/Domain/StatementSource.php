<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §13.1 - how a `BankStatement` reached the
 * system. `Ofx` is in the spec's list and accepted by the table's CHECK so a
 * parser can be added without a migration; only `Manual` and `Csv` have a
 * code path today, and no bank-API integration exists or is implied.
 *
 * Labels are literal English: `lang/en|fr/opes.php` is being edited
 * concurrently and this feature adds no keys to it.
 */
enum StatementSource: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case Ofx = 'ofx';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Keyed by hand',
            self::Csv => 'CSV import',
            self::Ofx => 'OFX import',
        };
    }
}
