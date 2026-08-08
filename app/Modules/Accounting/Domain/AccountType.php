<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * `ChartOfAccount.type` (02-accounting.md 2.1).
 *
 * SYSCOHADA class digits do not map one-to-one onto this enum: class 4
 * (tiers) legitimately holds both receivables (asset) and payables
 * (liability); class 5 (tresorerie) legitimately holds both cash/bank
 * (asset) and treasury credit lines (liability); class 8 (HAO) legitimately
 * holds both cession charges (expense) and cession income (revenue). There
 * is no "contra-asset" case in the enum - a cumulative-depreciation account
 * (28x) is still `asset` with `normal_balance = credit`.
 *
 * `allowedForClass()` is the "seed table's own class-to-type mapping" that
 * CoA-6 (02-accounting.md 2.2) validates new accounts against. It is
 * deliberately permissive per class - CoA-6's real bite, per CreateAccount /
 * UpdateAccount, is that a NEW account must inherit its exact type and
 * normal_balance from its parent, which was itself seeded correctly.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case Offbalance = 'offbalance';
    case Analytic = 'analytic';

    /**
     * @return list<self>
     */
    public static function allowedForClass(int $accountClass): array
    {
        return match ($accountClass) {
            1 => [self::Equity, self::Liability],
            2, 3 => [self::Asset],
            4 => [self::Asset, self::Liability],
            5 => [self::Asset, self::Liability],
            6 => [self::Expense],
            7 => [self::Revenue],
            8 => [self::Expense, self::Revenue],
            9 => [self::Offbalance, self::Analytic],
            default => [],
        };
    }
}
