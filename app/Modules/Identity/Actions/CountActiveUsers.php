<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * How many accounts can currently sign in.
 *
 * Exists as an Action rather than a model call so other modules can ask the
 * question without importing Identity\Models\User, which 00-core 6.2 rule 2
 * forbids. Suspended accounts are excluded: 00-core 10.5 never deletes a user,
 * so a raw row count would drift further from the truth every year.
 */
final class CountActiveUsers
{
    public function handle(): int
    {
        return User::query()->where('status', 'active')->count();
    }
}
