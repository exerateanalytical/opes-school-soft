<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * docs/specs/06-assets-stores.md §7.6. The signed-delta convention rides on
 * the row (`quantity`/`total_cost`), not the type; `inbound()` states which
 * direction each type is expected to carry.
 */
enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Sale = 'sale';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
    case OpeningBalance = 'opening_balance';

    public function inbound(): bool
    {
        return match ($this) {
            self::Receipt, self::TransferIn, self::AdjustmentIn,
            self::ReturnIn, self::OpeningBalance => true,
            self::Issue, self::TransferOut, self::AdjustmentOut,
            self::Sale, self::ReturnOut => false,
        };
    }

    /**
     * The compensating type a reversal movement carries (I11): the exact
     * opposite direction, named honestly rather than a negative receipt.
     */
    public function reversalType(): self
    {
        return match ($this) {
            self::Receipt => self::ReturnOut,
            self::Issue => self::ReturnIn,
            self::TransferOut => self::TransferIn,
            self::TransferIn => self::TransferOut,
            self::AdjustmentIn => self::AdjustmentOut,
            self::AdjustmentOut => self::AdjustmentIn,
            self::Sale => self::ReturnIn,
            self::ReturnIn => self::ReturnOut,
            self::ReturnOut => self::ReturnIn,
            self::OpeningBalance => self::AdjustmentOut,
        };
    }
}
