<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Actions\SweepUnfinishedWork;
use Illuminate\Console\Command;

/**
 * The scheduled pass that turns a stale held draft into a notification.
 */
final class SweepUnfinishedWorkCommand extends Command
{
    protected $signature = 'opes:forms:sweep-unfinished-work
        {--stale-after=60 : Minutes a held draft may sit untouched before it notifies}';

    protected $description = 'Notify owners of held form drafts nobody has returned to.';

    public function handle(SweepUnfinishedWork $sweep): int
    {
        $staleAfter = (int) $this->option('stale-after');

        $count = $sweep->handle($staleAfter < 1 ? 60 : $staleAfter);

        $this->info("Notified {$count} owner(s) of unfinished work.");

        return self::SUCCESS;
    }
}
