<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.8.
 *
 * `Queued` is the NORMAL steady state on a LAN deployment with no external
 * connectivity - the spec is explicit that the UI must say so rather than
 * render it as a failure, so it is deliberately distinct from `Failed`.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
