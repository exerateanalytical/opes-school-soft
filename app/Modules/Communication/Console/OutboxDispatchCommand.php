<?php

declare(strict_types=1);

namespace App\Modules\Communication\Console;

use App\Modules\Communication\Actions\DispatchOutbox;
use Illuminate\Console\Command;

/**
 * The scheduled drain of the outbox. Safe to run every minute: DispatchOutbox
 * claims each row under a lock, so overlapping runs cannot double-send.
 */
final class OutboxDispatchCommand extends Command
{
    protected $signature = 'opes:outbox:dispatch
        {--limit=200 : How many queued messages this run may attempt}
        {--driver= : Override the configured driver (log, null)}
        {--id= : Dispatch one message only, by id}';

    protected $description = 'Deliver queued outbox messages through the configured driver.';

    public function handle(DispatchOutbox $dispatch): int
    {
        $limit = (int) $this->option('limit');
        $driver = $this->option('driver');
        $id = $this->option('id');

        $tally = $dispatch->handle(
            limit: $limit < 1 ? 200 : $limit,
            driverName: is_string($driver) && $driver !== '' ? $driver : null,
            onlyId: is_numeric($id) ? (int) $id : null,
        );

        if ($tally['considered'] === 0) {
            $this->info('The outbox is empty; nothing was waiting.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Driver [%s]: %d considered, %d sent, %d failed, %d not configured, %d skipped.',
            $tally['driver'],
            $tally['considered'],
            $tally['sent'],
            $tally['failed'],
            $tally['disabled'],
            $tally['skipped'],
        ));

        if ($tally['failed'] > 0 || $tally['disabled'] > 0) {
            $this->line('Failed and not-configured messages stay in the outbox and can be retried from the Outbox screen.');
        }

        return self::SUCCESS;
    }
}
