<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
 * Shared fixtures for the Phase 12 API tests. Helper names carry the p12api
 * prefix (parallel-agent convention: function_exists-guarded AND globally
 * unique across every agent's helper files).
 */

if (! function_exists('p12apiSeedPermissions')) {
    /** Seed the permission catalogue so Spatie can resolve grants by name. */
    function p12apiSeedPermissions(): void
    {
        (new RolePermissionSeeder())->run();
    }
}

if (! function_exists('p12apiUserWithPermissions')) {
    /**
     * A user holding exactly the given permissions - granted directly, not
     * through a role, so each test states its authorization surface
     * explicitly.
     */
    function p12apiUserWithPermissions(Permission ...$permissions): User
    {
        p12apiSeedPermissions();

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission->value);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p12apiBearerHeaders')) {
    /**
     * Issue a token for the user with the given ability strings and return
     * ready-to-send request headers.
     *
     * @param  list<string>  $abilities
     * @return array<string, string>
     */
    function p12apiBearerHeaders(User $user, array $abilities): array
    {
        $token = $user->createToken('test-token', $abilities);

        return [
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json',
        ];
    }
}
