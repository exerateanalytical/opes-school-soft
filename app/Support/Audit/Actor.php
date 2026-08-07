<?php

declare(strict_types=1);

namespace App\Support\Audit;

use InvalidArgumentException;

/**
 * Who performed an audited action.
 *
 * Lives in the shared kernel deliberately. docs/specs/00-core.md 14 requires
 * every audit entry to name its actor, while 6.2 forbids a module from
 * importing another module's Models - so passing an Identity\Models\User
 * across a module boundary would make those two rules contradict each other.
 * A plain value object satisfies both.
 *
 * `name` is the name AT THE TIME. It is denormalised onto the audit row so an
 * entry stays legible after the account is renamed or deactivated.
 */
final readonly class Actor
{
    public function __construct(
        public ?int $id,
        public string $name,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException(
                'An Actor must have a name; an unattributable audit entry is worse than none.'
            );
        }
    }

    /** Scheduled jobs, console commands, migrations - anything unattended. */
    public static function system(): self
    {
        return new self(null, 'system');
    }
}
