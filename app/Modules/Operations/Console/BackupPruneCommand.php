<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Operations\Actions\PruneBackups;
use Illuminate\Console\Command;

final class BackupPruneCommand extends Command
{
    protected $signature = 'opes:backup:prune';

    protected $description = 'Apply GFS retention. Never removes the last healthy backup.';

    public function handle(PruneBackups $prune): int
    {
        $deleted = $prune->handle();

        $this->info($deleted === 0
            ? 'Nothing needed pruning.'
            : "Removed {$deleted} expired backup(s).");

        $this->line('The most recent healthy backup is never removed, whatever the retention says.');

        return self::SUCCESS;
    }
}
