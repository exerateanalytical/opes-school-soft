<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.2. */
enum BookCondition: string
{
    case NewCopy = 'new';
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
}
