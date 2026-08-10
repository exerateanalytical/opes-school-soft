<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §11.7 - the three states of a till.
 *
 * `Reconciled` is deliberately reachable only after `Closed`: closing states
 * what the cashier counted, reconciling states that a supervisor agreed with
 * it. v1 conflated the two, which is how a short till got "signed off" by the
 * same person who was short.
 */
enum CashDeskSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Reconciled = 'reconciled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Reconciled => 'Reconciled',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
