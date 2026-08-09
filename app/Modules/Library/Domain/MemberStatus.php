<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.3. */
enum MemberStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Closed = 'closed';
}
