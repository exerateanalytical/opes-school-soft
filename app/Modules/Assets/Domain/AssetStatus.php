<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.2. `in_progress` produces no depreciation (A14);
 * the terminal statuses freeze every mutating Action except reversal (A12).
 */
enum AssetStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case InService = 'in_service';
    case Idle = 'idle';
    case UnderMaintenance = 'under_maintenance';
    case Impaired = 'impaired';
    case Disposed = 'disposed';
    case WrittenOff = 'written_off';
    case Lost = 'lost';

    /** A12: disposed/written-off/lost assets refuse every mutating Action. */
    public function isFrozen(): bool
    {
        return in_array($this, [self::Disposed, self::WrittenOff, self::Lost], true);
    }
}
