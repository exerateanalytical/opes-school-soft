<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Models\OutboxMessage;
use Illuminate\Support\Facades\Log;

/**
 * The v1 driver: writes the message to the Laravel log and reports it sent.
 *
 * This is the correct default while 08-operations 11.1's gateway decision is
 * deferred - the school's staff still get a truthful outbox (they can see
 * exactly what the system decided to send, to whom, in which language), and
 * no credit is spent because nothing leaves the building.
 */
final class LogDriver implements MessageDriver
{
    public function name(): string
    {
        return 'log';
    }

    public function send(OutboxMessage $message): DriverResult
    {
        // A blank recipient is a caller bug, not a transport failure - but it
        // must not be logged as "sent" either, or the office would believe a
        // parent was told something nobody was told.
        if (trim($message->recipient) === '') {
            return DriverResult::failed('No recipient address on the message.');
        }

        Log::info('opes.outbox.delivered', [
            'outbox_message_id' => $message->getKey(),
            'channel' => $message->channel->value,
            'recipient' => $message->recipient,
            'language' => $message->language,
            'subject_line' => $message->subject_line,
            'body' => $message->body,
            'driver' => $this->name(),
        ]);

        return DriverResult::sent();
    }
}
