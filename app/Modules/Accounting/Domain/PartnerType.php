<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §8.2 - the five polymorphic counterparty
 * kinds a `JournalEntryLine` (or a `Lettering` group) on a collective
 * account can carry. Deliberately NOT a foreign key (§8.3): `partner_id`
 * resolves against a different table per case, and MySQL has no
 * polymorphic FK.
 */
enum PartnerType: string
{
    case Student = 'student';
    case Guardian = 'guardian';
    case Supplier = 'supplier';
    case Staff = 'staff';
    case Organisation = 'organisation';
}
