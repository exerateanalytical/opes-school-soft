<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §13.1 `ReconciliationMatch.match_type`.
 *
 * Derived from the two side counts rather than chosen by a human - a match
 * of three statement lines against one ledger line IS `many_to_one`, and
 * letting a caller assert otherwise would put a label on the row that its
 * own join tables contradict.
 */
enum ReconciliationMatchType: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';
    case ManyToMany = 'many_to_many';

    /** @param int $statementLines the relevé side; @param int $ledgerLines the books side */
    public static function forCounts(int $statementLines, int $ledgerLines): self
    {
        return match (true) {
            $statementLines === 1 && $ledgerLines === 1 => self::OneToOne,
            $statementLines === 1 => self::OneToMany,
            $ledgerLines === 1 => self::ManyToOne,
            default => self::ManyToMany,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OneToOne => '1 : 1',
            self::OneToMany => '1 : n',
            self::ManyToOne => 'n : 1',
            self::ManyToMany => 'n : n',
        };
    }
}
