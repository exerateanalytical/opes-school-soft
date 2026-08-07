<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use Spatie\Permission\Models\Role;

/**
 * How many roles are actually seeded in this installation.
 *
 * Deliberately counts rows rather than Role::cases(): the enum says what the
 * product supports, the table says what this install has. A gap between the
 * two means the seeder has not run, and reporting the enum count would hide
 * exactly that.
 */
final class CountConfiguredRoles
{
    public function handle(): int
    {
        return Role::query()->count();
    }
}
