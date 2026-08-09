<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Which legs of the route the student rides (phase-10 plan §3 row 3).
 * Morning-only and evening-only riders are real (a parent drops off on the
 * way to work); the roster report shows the direction so the escort knows
 * who to expect on which run.
 */
enum TransportDirection: string
{
    case Both = 'both';
    case Pickup = 'pickup';
    case Dropoff = 'dropoff';
}
