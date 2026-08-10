<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * Who was paid (docs/specs/02-accounting.md §21.3).
 *
 * `Supplier` and `Staff` carry a `payee_id` pointing at suppliers.id /
 * users.id respectively - validated with a DB::table lookup in
 * RecordExpense, never a cross-module FK (00-core §6.2). `Other` is the
 * market trader with no record anywhere, which is the case this whole
 * document exists for.
 *
 * A REGISTERED supplier is deliberately still allowed here (petty cash at
 * the counter of a supplier we happen to have on file), but the screen
 * steers the operator to the Procurement flow, exactly as §21.3 requires.
 */
enum ExpensePayeeType: string
{
    case Supplier = 'supplier';
    case Staff = 'staff';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Staff => 'Staff member',
            self::Other => 'Other / unregistered',
        };
    }

    /** The table a `payee_id` of this type refers to, if any. */
    public function referenceTable(): ?string
    {
        return match ($this) {
            self::Supplier => 'suppliers',
            self::Staff => 'users',
            self::Other => null,
        };
    }

    /**
     * The partner tuple type the posting payload carries, if the school's
     * rule chooses to letter the expense against a third party.
     */
    public function partnerType(): ?string
    {
        return match ($this) {
            self::Supplier => 'supplier',
            self::Staff => 'staff',
            self::Other => null,
        };
    }
}
