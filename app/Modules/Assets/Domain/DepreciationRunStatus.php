<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §4.1. Transitions are one-way conditional UPDATEs
 * with affected-rows checks: draft → calculated → approved → posted, with
 * cancelled reachable only before posting.
 */
enum DepreciationRunStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
