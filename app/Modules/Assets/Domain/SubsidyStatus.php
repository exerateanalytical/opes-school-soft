<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §6.3. `fully_released` when Σ releases reaches the
 * granted amount (by the same min() cap as depreciation); `clawed_back`
 * reverses the unreleased balance against a liability to the donor.
 */
enum SubsidyStatus: string
{
    case Active = 'active';
    case FullyReleased = 'fully_released';
    case ClawedBack = 'clawed_back';
}
