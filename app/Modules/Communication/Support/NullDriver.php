<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Models\OutboxMessage;

/**
 * "No channel is configured on this instance."
 *
 * Everything it is handed lands in `disabled`, which is precisely the state
 * 300004 created the enum case for: the row survives, the office sees what
 * WOULD have gone out, and a later-configured channel drains it by retry.
 * Nothing in the product fails because a message could not leave.
 */
final class NullDriver implements MessageDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function send(OutboxMessage $message): DriverResult
    {
        return DriverResult::disabled(
            'No delivery channel is configured for '.$message->channel->value.' on this instance.'
        );
    }
}
