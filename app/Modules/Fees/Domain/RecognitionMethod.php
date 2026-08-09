<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.2 / §6, C4 - revenue cut-off. `straight_line_over_period`
 * requires a service period; `on_collection` defers to 4191 until cash
 * arrives and is reserved for genuinely contingent items.
 */
enum RecognitionMethod: string
{
    case OnIssue = 'on_issue';
    case StraightLineOverPeriod = 'straight_line_over_period';
    case OnCollection = 'on_collection';
}
