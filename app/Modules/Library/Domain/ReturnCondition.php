<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.4. */
enum ReturnCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case Lost = 'lost';
}
