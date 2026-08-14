<?php

declare(strict_types=1);

use App\Modules\Activities\Actions\CreateActivity;
use App\Modules\Activities\Actions\EnrolStudent;
use App\Modules\Activities\Actions\ScheduleSession;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\MembershipRole;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Models\ActivityMembership;
use App\Modules\Activities\Models\ActivitySession;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Factories\GuardianFactory;
use Database\Factories\StudentFactory;
use Database\Factories\StudentGuardianFactory;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Activities suites. Prefix `actv`, every helper
 * function_exists-guarded (00-core test discipline; names must never
 * collide with another agent's).
 */
if (! function_exists('actvUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function actvUser(string ...$permissions): User
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

if (! function_exists('actvManager')) {
    /** The usual operator: holds both activity abilities. */
    function actvManager(): User
    {
        return actvUser(ActivityPermission::VIEW, ActivityPermission::MANAGE);
    }
}

if (! function_exists('actvActor')) {
    function actvActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('actvActivity')) {
    /**
     * A club, created through the REAL gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function actvActivity(User $user, array $overrides = []): Activity
    {
        return app(CreateActivity::class)->handle([
            'name' => 'Club '.fake()->unique()->numberBetween(1, 999_999),
            'type' => 'club',
            'venue' => 'Hall A',
            ...$overrides,
        ], actvActor($user));
    }
}

if (! function_exists('actvExcursion')) {
    /**
     * An excursion with the full trip envelope, created through the gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function actvExcursion(User $user, array $overrides = []): Activity
    {
        return app(CreateActivity::class)->handle([
            'name' => 'Excursion '.fake()->unique()->numberBetween(1, 999_999),
            'type' => 'excursion',
            'destination' => 'Limbe Wildlife Centre',
            'departure_at' => '2026-09-10 07:00',
            'return_at' => '2026-09-10 18:00',
            ...$overrides,
        ], actvActor($user));
    }
}

if (! function_exists('actvStudentId')) {
    /** A student row this module may only ever read via DB::table. */
    function actvStudentId(): int
    {
        $student = StudentFactory::new()->createOne();

        return (int) $student->getKey();
    }
}

if (! function_exists('actvLinkedGuardianId')) {
    /** A guardian with a currently-valid link to the given student. */
    function actvLinkedGuardianId(int $studentId): int
    {
        $guardian = GuardianFactory::new()->createOne();

        StudentGuardianFactory::new()->createOne([
            'student_id' => $studentId,
            'guardian_id' => $guardian->getKey(),
        ]);

        return (int) $guardian->getKey();
    }
}

if (! function_exists('actvUnlinkedGuardianId')) {
    /** A guardian with NO link to anyone. */
    function actvUnlinkedGuardianId(): int
    {
        return (int) GuardianFactory::new()->createOne()->getKey();
    }
}

if (! function_exists('actvMembership')) {
    /** Enrols a (fresh or given) student through the real gate. */
    function actvMembership(User $user, Activity $activity, ?int $studentId = null): ActivityMembership
    {
        return app(EnrolStudent::class)->handle(
            (int) $activity->getKey(),
            $studentId ?? actvStudentId(),
            MembershipRole::Member,
            Carbon::parse('2026-09-01'),
            actvActor($user),
        );
    }
}

if (! function_exists('actvSession')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function actvSession(User $user, Activity $activity, array $overrides = []): ActivitySession
    {
        return app(ScheduleSession::class)->handle((int) $activity->getKey(), [
            'scheduled_on' => '2026-09-05',
            'starts_at' => '15:00',
            'ends_at' => '17:00',
            'venue' => 'Sports Field',
            ...$overrides,
        ], actvActor($user));
    }
}
