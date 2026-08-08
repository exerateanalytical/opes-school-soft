<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\AssignAllocationTeacher;
use App\Modules\Assessment\Actions\DelegateMarkEntry;
use App\Modules\Assessment\Actions\SaveMark;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Database\Factories\MarkFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * 01-assessment §7.5 - the two assignment sources Mark::mayEnter() resolves.
 * Until migration 220016 these tables did not exist and the schema-guarded
 * gate denied every plain Teacher (the correct failure direction, and also a
 * module that could not do its job). These are the POSITIVE paths T22 never
 * had: a teacher who IS assigned or delegated can finally enter marks.
 */

if (! function_exists('assignmentUserAs')) {
    function assignmentUserAs(Role $role, string $name = 'Someone'): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create(['name' => $name, 'status' => 'active']);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('lets an assigned teacher enter a mark for their own allocation', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $teacher = assignmentUserAs(Role::Teacher, 'Ngwa Bertrand');

    $scenario = MarkFactory::scenario(students: 1);

    actingAs($officer);
    app(AssignAllocationTeacher::class)->handle((int) $scenario['allocation'], $teacher->id, $officer->toAuditActor());

    actingAs($teacher);
    expect(Mark::mayEnter((int) $scenario['allocation']))->toBeTrue();

    $mark = Mark::query()->findOrFail($scenario['marks'][0]);
    app(SaveMark::class)->handle($mark, MarkState::Scored, '12.500');

    expect($mark->refresh()->score)->toBe('12.500');
});

it('still refuses the same teacher on an allocation they are NOT assigned', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $teacher = assignmentUserAs(Role::Teacher, 'Ngwa Bertrand');

    // Two scenario() calls: each builds its own allocation, which is the
    // whole point - one the teacher is assigned to, one they are not.
    $assigned = MarkFactory::scenario(students: 1);
    $foreign = MarkFactory::scenario(students: 1);

    actingAs($officer);
    app(AssignAllocationTeacher::class)->handle((int) $assigned['allocation'], $teacher->id, $officer->toAuditActor());

    actingAs($teacher);
    expect(Mark::mayEnter((int) $assigned['allocation']))->toBeTrue();
    expect(Mark::mayEnter((int) $foreign['allocation']))->toBeFalse();

    $foreignMark = Mark::query()->findOrFail($foreign['marks'][0]);
    expect(fn () => app(SaveMark::class)->handle($foreignMark, MarkState::Scored, '10.000'))
        ->toThrow(AuthorizationException::class);
});

it('lets a delegate enter marks while the delegation window is open', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $delegate = assignmentUserAs(Role::Teacher, 'Mballa Chantal');

    $scenario = MarkFactory::scenario(students: 1);

    actingAs($officer);
    app(DelegateMarkEntry::class)->handle(
        subjectAllocationId: (int) $scenario['allocation'],
        delegateUserId: $delegate->id,
        reason: 'Titular teacher resigned in November.',
        actor: $officer->toAuditActor(),
    );

    actingAs($delegate);
    expect(Mark::mayEnter((int) $scenario['allocation']))->toBeTrue();
});

it('denies a delegate outside the validity window', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $delegate = assignmentUserAs(Role::Teacher, 'Mballa Chantal');

    $scenario = MarkFactory::scenario(students: 1);

    $lastMonth = Carbon::parse(BusinessDate::today())->subMonth();

    actingAs($officer);
    app(DelegateMarkEntry::class)->handle(
        subjectAllocationId: (int) $scenario['allocation'],
        delegateUserId: $delegate->id,
        reason: 'Covered while the titular teacher was on leave.',
        validFrom: $lastMonth->copy()->startOfMonth()->toDateString(),
        validTo: $lastMonth->copy()->endOfMonth()->toDateString(),
        actor: $officer->toAuditActor(),
    );

    actingAs($delegate);
    expect(Mark::mayEnter((int) $scenario['allocation']))->toBeFalse();
});

it('ends a delegation by end-dating it, never deleting - the dossier cites it', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $delegate = assignmentUserAs(Role::Teacher, 'Mballa Chantal');

    $scenario = MarkFactory::scenario(students: 1);

    actingAs($officer);
    $delegationId = app(DelegateMarkEntry::class)->handle(
        subjectAllocationId: (int) $scenario['allocation'],
        delegateUserId: $delegate->id,
        reason: 'Interim cover.',
        actor: $officer->toAuditActor(),
    );

    app(DelegateMarkEntry::class)->end($delegationId, $officer->toAuditActor());

    // The row survives as history; only its window closed.
    expect(\Illuminate\Support\Facades\DB::table('mark_entry_delegations')->where('id', $delegationId)->exists())
        ->toBeTrue();
});

it('refuses a delegation with no reason', function () {
    $officer = assignmentUserAs(Role::ExamsOfficer, 'Officer');
    $delegate = assignmentUserAs(Role::Teacher, 'Mballa Chantal');

    $scenario = MarkFactory::scenario(students: 1);

    actingAs($officer);
    expect(fn () => app(DelegateMarkEntry::class)->handle(
        subjectAllocationId: (int) $scenario['allocation'],
        delegateUserId: $delegate->id,
        reason: '   ',
        actor: $officer->toAuditActor(),
    ))->toThrow(DomainException::class, 'reason');
});

it('refuses assignment management to a plain teacher', function () {
    $teacher = assignmentUserAs(Role::Teacher, 'Ngwa Bertrand');
    $other = assignmentUserAs(Role::Teacher, 'Someone Else');

    $scenario = MarkFactory::scenario(students: 1);

    actingAs($teacher);
    expect(fn () => app(AssignAllocationTeacher::class)->handle((int) $scenario['allocation'], $other->id, $teacher->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});
