<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * The verdict of a single go-live readiness check (00-core §16).
 */
enum SetupCheckStatus: string
{
    /** Configured and safe to rely on. */
    case Pass = 'pass';

    /** Not configured, and something concrete refuses to run until it is. */
    case Blocked = 'blocked';

    /** Not configured, but nothing refuses - it degrades quietly. */
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Ready',
            self::Blocked => 'Blocks go-live',
            self::Warning => 'Should be set',
        };
    }
}
