<?php

declare(strict_types=1);

namespace App\Modules\Communication\Domain;

/**
 * The outbox state machine, mirroring `outbox_messages.status`.
 *
 *      queued ──► sent
 *         │
 *         ├────► failed ──► (retry) ──► queued
 *         └────► disabled ─► (retry) ──► queued
 *
 * `disabled` is the state that makes 00-core 3's promise true: the channel
 * is not configured on this instance, so nothing left the building, but the
 * row exists and the office can see what WOULD have gone out.
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Disabled => 'Not configured',
        };
    }

    /** x-status-pill tone. Colour never carries the meaning alone (09-ui 10). */
    public function tone(): string
    {
        return match ($this) {
            self::Sent => 'ok',
            self::Failed => 'red',
            self::Queued, self::Disabled => 'amber',
        };
    }

    /** Whether the dispatcher will pick this row up. */
    public function isPending(): bool
    {
        return $this === self::Queued;
    }

    /** Whether the row may be put back on the queue by hand. */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Disabled;
    }
}
