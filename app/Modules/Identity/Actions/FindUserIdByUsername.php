<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\Username;
use App\Modules\Identity\Models\User;

/**
 * Identity's door for "is there a user with this handle, and which one".
 *
 * The sibling of FindUserIdByEmail, and an ID for the same reason: 00-core
 * 6.2 rule 2 forbids another module importing `Identity\Models\User`, and
 * returning one would smuggle that dependency out through the return type.
 *
 * The lookup normalises first (trim, lower-case) so `@Amina.N` typed into the
 * compose field finds the stored `amina.n`. A leading `@` is stripped: users
 * write handles that way everywhere else, and refusing the form they are used
 * to would read as "no such user".
 */
final class FindUserIdByUsername
{
    public function handle(string $username): ?int
    {
        $value = Username::normalise(ltrim(trim($username), '@'));

        if ($value === '') {
            return null;
        }

        $id = User::query()->where('username', $value)->value('id');

        return $id === null ? null : (int) $id;
    }
}
