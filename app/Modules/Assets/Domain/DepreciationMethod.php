<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.1. `none` is legitimate: land.
 */
enum DepreciationMethod: string
{
    case None = 'none';
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';

    public function depreciates(): bool
    {
        return $this !== self::None;
    }
}
