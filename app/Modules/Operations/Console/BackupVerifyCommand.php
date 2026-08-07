<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Operations\Actions\VerifyBackup;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Re-hash the least-recently-verified backups, a bounded number per run.
 *
 * Bounded on purpose (08-operations 3.4): an unbounded verification sweep on a
 * nightly timer re-hashes every file the school has ever kept, and on a year of
 * daily dumps that is hours of disk thrash during which nothing else can run.
 * NULLs sort first in MySQL, so a never-verified backup is always picked before
 * one that has been checked.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'opes:backup:verify {--all : Verify every backup, not just this run\'s budget}';

    protected $description = 'Re-check backup checksums, oldest verification first.';

    public function handle(VerifyBackup $verify): int
    {
        $budget = (int) config('opes.backup.verify_budget_per_run');

        $query = Backup::query()
            ->where('status', '!=', BackupStatus::Running->value)
            ->orderByRaw('verified_at IS NULL DESC')
            ->orderBy('verified_at');

        if ($this->option('all') !== true) {
            $query->limit(max(1, $budget));
        }

        /** @var Collection<int, Backup> $candidates */
        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->info('There are no backups to verify yet.');

            return self::SUCCESS;
        }

        $corrupt = 0;

        foreach ($candidates as $candidate) {
            $backup = $verify->handle($candidate);

            if ($backup->status() === BackupStatus::Healthy) {
                $this->line('  OK      '.basename($backup->path));

                continue;
            }

            $corrupt++;
            $this->error('  CORRUPT '.basename($backup->path).' - '.(string) $backup->failure_detail);
        }

        if ($corrupt > 0) {
            $this->line('');
            $this->error("{$corrupt} backup(s) can no longer be trusted.");
            $this->line('Take a fresh backup now: php artisan opes:backup:run');

            return self::FAILURE;
        }

        $this->info('Verified '.$candidates->count().' backup(s).');

        return self::SUCCESS;
    }
}
