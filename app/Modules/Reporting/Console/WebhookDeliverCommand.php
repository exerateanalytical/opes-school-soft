<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Actions\Webhooks\DeliverPendingWebhooks;
use Illuminate\Console\Command;

/**
 * The scheduled drain of due webhook deliveries - pending sends and
 * failures whose backoff window has elapsed.
 */
final class WebhookDeliverCommand extends Command
{
    protected $signature = 'opes:webhooks:deliver
        {--limit=100 : How many due deliveries this run may attempt}';

    protected $description = 'Sign and send queued webhook deliveries that are due.';

    public function handle(DeliverPendingWebhooks $deliver): int
    {
        $limit = (int) $this->option('limit');
        $tally = $deliver->handle($limit < 1 ? 100 : $limit);

        if ($tally['considered'] === 0) {
            $this->info('No webhook deliveries are due.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d considered, %d delivered, %d failed (will retry), %d exhausted.',
            $tally['considered'],
            $tally['delivered'],
            $tally['failed'],
            $tally['exhausted'],
        ));

        return self::SUCCESS;
    }
}
