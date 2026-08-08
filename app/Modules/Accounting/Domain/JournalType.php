<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * 02-accounting §3. A Journal's type drives the statutory grouping of the
 * livre-journal and, for the three treasury types, the requirement that the
 * journal carry a `treasury_account_id` (§3's CHECK constraint).
 */
enum JournalType: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';
    case Payroll = 'payroll';
    case OperationsDiverses = 'operations_diverses';
    case Opening = 'opening';
    case Closing = 'closing';

    /**
     * The three treasury types. Mirrors the database CHECK
     * `type NOT IN ('cash','bank','mobile_money') OR treasury_account_id IS NOT NULL`
     * so Action-level validation (the belt) says exactly what the CHECK (the
     * braces) enforces, and the two cannot silently drift apart.
     */
    public function requiresTreasuryAccount(): bool
    {
        return match ($this) {
            self::Cash, self::Bank, self::MobileMoney => true,
            default => false,
        };
    }

    public function label(string $locale = 'en'): string
    {
        return __('opes.journal_type.'.$this->value, [], $locale);
    }
}
