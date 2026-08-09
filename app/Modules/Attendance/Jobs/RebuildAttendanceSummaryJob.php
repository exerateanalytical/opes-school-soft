<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Jobs;

use App\Modules\Attendance\Actions\RebuildAttendanceSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The queued wrapper around RebuildAttendanceSummary (07-students §9.8):
 * register submit/amend and justification changes re-queue the affected
 * period rollups instead of recomputing them in the request. Idempotent —
 * the rebuild is a full recomputation keyed on (enrollment, period), so
 * running it twice converges.
 */
final class RebuildAttendanceSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $registerId) {}

    public function handle(RebuildAttendanceSummary $rebuild): void
    {
        $rebuild->handle($this->registerId);
    }
}
