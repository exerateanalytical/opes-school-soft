<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Domain\MessageStatus;

/**
 * What a driver made of one message. Deliberately a value object rather than
 * exceptions-for-control-flow: "this channel is not configured here" is a
 * normal, expected outcome (00-core 3), not an error.
 */
final readonly class DriverResult
{
    private function __construct(
        public MessageStatus $status,
        public ?string $reason,
    ) {}

    public static function sent(): self
    {
        return new self(MessageStatus::Sent, null);
    }

    public static function failed(string $reason): self
    {
        return new self(MessageStatus::Failed, mb_substr(trim($reason), 0, 255));
    }

    /** The channel is not configured on this instance; keep the evidence. */
    public static function disabled(string $reason): self
    {
        return new self(MessageStatus::Disabled, mb_substr(trim($reason), 0, 255));
    }
}
