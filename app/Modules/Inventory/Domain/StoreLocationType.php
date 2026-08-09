<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * docs/specs/06-assets-stores.md §7.4 - the mockup's Location filter values.
 */
enum StoreLocationType: string
{
    case Store = 'store';
    case Lab = 'lab';
    case AvRoom = 'av_room';
    case Library = 'library';
    case Kitchen = 'kitchen';
    case Classroom = 'classroom';
}
