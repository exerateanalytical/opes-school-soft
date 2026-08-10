<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Models\OutboxMessage;

/**
 * The seam between the outbox and whatever eventually carries a message.
 *
 * 08-operations 11.1 defers the commercial SMS gateway decision (credit and
 * billing), so v1 ships NO provider integration and no HTTP client. What
 * ships is this interface plus a `log` driver, which is enough to prove and
 * operate the whole queued -> sent loop; adding a real gateway later is a
 * new class and a config value, not a change to any Action or screen.
 */
interface MessageDriver
{
    /** The value that selects this driver in config (`log`, `null`, ...). */
    public function name(): string;

    /**
     * Attempt delivery of ONE message.
     *
     * The driver does not touch the row's status columns - DispatchOutbox
     * owns the state machine. It returns a DriverResult saying what it did.
     */
    public function send(OutboxMessage $message): DriverResult;
}
