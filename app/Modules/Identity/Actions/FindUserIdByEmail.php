<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Identity's door for "is there a user with this address, and which one".
 *
 * It returns an ID, not a model, on purpose: 00-core §6.2 rule 2 forbids
 * another module importing `Identity\Models\User`, and handing one back
 * across the boundary would smuggle the same dependency out through the
 * return type. Every caller outside Identity works in user IDs already -
 * Communication's StartThread takes `list<int> $participantUserIds`, HR
 * stores `staff_members.portal_user_id` - so an ID is not a downgrade, it is
 * what they wanted.
 */
final class FindUserIdByEmail
{
    public function handle(string $email): ?int
    {
        $id = User::query()->where('email', trim($email))->value('id');

        return $id === null ? null : (int) $id;
    }
}
