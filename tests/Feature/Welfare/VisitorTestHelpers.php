<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\CheckInVisitor;
use App\Modules\Welfare\Domain\VisitorHostType;
use App\Modules\Welfare\Domain\VisitorPermission;
use App\Modules\Welfare\Models\VisitorLog;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 10 W4 visitor suite. Prefix p10Visitor,
 * every helper function_exists-guarded (00-core test discipline; names must
 * never collide with another agent's).
 */
if (! function_exists('p10VisitorUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p10VisitorUser(string ...$permissions): User
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

if (! function_exists('p10VisitorFrontDesk')) {
    /** The usual operator: holds visitor.manage (the FrontDesk seed). */
    function p10VisitorFrontDesk(): User
    {
        return p10VisitorUser(VisitorPermission::MANAGE);
    }
}

if (! function_exists('p10VisitorActor')) {
    function p10VisitorActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p10VisitorStudentId')) {
    /** A bare student row (a possible student host), via DB. */
    function p10VisitorStudentId(): int
    {
        $suffix = Str::upper(Str::random(8));

        return (int) DB::table('students')->insertGetId([
            'matricule' => 'OS-26-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2026/'.$suffix,
            'first_name' => 'Hosted',
            'last_name' => 'Child '.$suffix,
            'date_of_birth' => '2012-05-11',
            'place_of_birth' => 'Bafoussam',
            'gender' => 'male',
            'nationality' => 'CM',
            'status' => 'active',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p10VisitorCheckIn')) {
    /** A visitor checked in through the REAL door. */
    function p10VisitorCheckIn(
        User $user,
        string $name = 'Ngwa Franklin',
        string $badge = 'V-01',
        VisitorHostType $hostType = VisitorHostType::Office,
        ?int $hostId = null,
        ?string $idRef = null,
        ?Carbon $checkedInAt = null,
    ): VisitorLog {
        return app(CheckInVisitor::class)->handle(
            $name,
            '677000000',
            $idRef,
            'Fee payment enquiry',
            $hostType,
            $hostId,
            $badge,
            $checkedInAt ?? Carbon::now(),
            p10VisitorActor($user),
        );
    }
}
