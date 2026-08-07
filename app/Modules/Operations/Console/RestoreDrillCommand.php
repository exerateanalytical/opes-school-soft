<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Operations\Actions\RunRestoreDrill;
use Illuminate\Console\Command;

/**
 * The control that turns "we have backups" into "we have proven we can
 * restore" (08-operations 3.6).
 */
final class RestoreDrillCommand extends Command
{
    protected $signature = 'opes:backup:drill';

    protected $description = 'Restore the newest healthy backup into a scratch schema and prove it works.';

    public function handle(RunRestoreDrill $drill): int
    {
        $this->line('Restoring the newest healthy backup into a temporary database...');

        $result = $drill->handle();

        if ($result->status !== 'passed') {
            $this->error('The restore drill FAILED after '.$result->assertions_passed.' checks.');
            $this->line('  Reason: '.(string) $result->failure_detail);
            $this->line('');
            $this->line('Until this passes you do not have a backup you can rely on.');
            $this->line('Take a fresh backup (php artisan opes:backup:run), run this again, and');
            $this->line('if it fails a second time send the diagnostics bundle to support.');

            return self::FAILURE;
        }

        $this->info("Restore drill passed. {$result->assertions_passed} checks confirmed.");
        $this->line('The temporary database has been dropped.');

        return self::SUCCESS;
    }
}
