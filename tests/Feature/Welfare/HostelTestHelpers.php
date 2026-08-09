<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Actions\SaveHostel;
use App\Modules\Welfare\Actions\SaveRoom;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\Hostel;
use App\Modules\Welfare\Models\HostelRoom;
use App\Support\Audit\Actor;
use Database\Factories\EnrollmentFactory;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 10 W2 hostel suites. Prefix p10Hostel,
 * every helper function_exists-guarded (00-core test discipline; names
 * must never collide with another agent's).
 */
if (! function_exists('p10HostelUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p10HostelUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p10HostelManager')) {
    /** The usual operator: holds both hostel abilities. */
    function p10HostelManager(): User
    {
        return p10HostelUser(HostelPermission::VIEW, HostelPermission::MANAGE);
    }
}

if (! function_exists('p10HostelActor')) {
    function p10HostelActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p10Hostel')) {
    /**
     * A hostel created through the REAL gate. Mixed-gender by default so
     * allocation fixtures don't trip the gender gate unless a test wants
     * to.
     *
     * @param  array<string, mixed>  $overrides
     */
    function p10Hostel(User $user, array $overrides = []): Hostel
    {
        return app(SaveHostel::class)->handle(null, [
            'code' => 'HB'.fake()->unique()->numberBetween(1, 999_999),
            'name' => 'Hostel '.fake()->unique()->numberBetween(1, 999_999),
            'gender' => 'mixed',
            'is_active' => true,
            ...$overrides,
        ], p10HostelActor($user));
    }
}

if (! function_exists('p10HostelRoom')) {
    /**
     * A room with two beds (B1, B2) in the given hostel, capacity 2
     * unless overridden, all through the real Actions.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<string>|null  $beds  null = ['B1', 'B2']
     */
    function p10HostelRoom(User $user, Hostel $hostel, array $overrides = [], ?array $beds = null): HostelRoom
    {
        $room = app(SaveRoom::class)->handle(null, [
            'hostel_id' => (int) $hostel->getKey(),
            'name' => 'R'.fake()->unique()->numberBetween(1, 999_999),
            'capacity' => 2,
            ...$overrides,
        ], p10HostelActor($user));

        app(SaveBeds::class)->handle((int) $room->getKey(), $beds ?? ['B1', 'B2'], p10HostelActor($user));

        return $room->refresh();
    }
}

if (! function_exists('p10HostelEnrollmentId')) {
    /**
     * An ACTIVE enrollment whose student carries the given gender (the
     * factory hardcodes 'male'; hostels care, so flip it here).
     */
    function p10HostelEnrollmentId(string $gender = 'male'): int
    {
        $enrollment = EnrollmentFactory::new()->createOne();

        DB::table('students')
            ->where('id', $enrollment->student_id)
            ->update(['gender' => $gender]);

        return (int) $enrollment->getKey();
    }
}

if (! function_exists('p10HostelWithdrawnEnrollmentId')) {
    function p10HostelWithdrawnEnrollmentId(): int
    {
        $enrollment = EnrollmentFactory::new()->withdrawn()->createOne();

        return (int) $enrollment->getKey();
    }
}
