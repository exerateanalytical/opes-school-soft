<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §13.1 - where one line of the relevé stands.
 *
 * `Ignored` is in the spec and is carried faithfully, but it does NOT let a
 * line vanish from the arithmetic: BuildReconciliationStatement counts an
 * ignored line in "opérations au relevé non encore comptabilisées" exactly
 * as it counts an unmatched one, so §13.3's rule - anything the bank
 * recorded and the books did not must be POSTED, not reconciled away - still
 * holds and the session still refuses to close. Ignoring annotates; it never
 * excuses.
 */
enum StatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Matched => 'Matched',
            self::Ignored => 'Set aside',
        };
    }
}
