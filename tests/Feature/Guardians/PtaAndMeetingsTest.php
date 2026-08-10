<?php

declare(strict_types=1);

use App\Modules\Guardians\Actions\Pta\AppointPtaOfficer;
use App\Modules\Guardians\Actions\Pta\RecordPtaMeetingMinutes;
use App\Modules\Guardians\Actions\Pta\SchedulePtaMeeting;
use App\Modules\Guardians\Actions\RecordMeetingOutcome;
use App\Modules\Guardians\Actions\ScheduleGuardianMeeting;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Guardians\Models\PtaOfficer;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function ptaActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

function ptaGuardianId(): int
{
    $id = (int) DB::table('guardians')->value('id');

    if ($id === 0) {
        test()->markTestSkipped('Needs a guardian fixture from another slice.');
    }

    return $id;
}

it('schedules an individual guardian meeting', function (): void {
    $actor = ptaActor();
    $guardianId = ptaGuardianId();

    $meeting = app(ScheduleGuardianMeeting::class)->handle(
        $guardianId, null, now()->addDays(3)->toDateString(),
        MeetingType::ParentTeacher, MeetingRequestedBy::School, (int) $actor->getKey(),
        'Principal\'s office', 'Discuss term progress.',
    );

    expect($meeting->status)->toBe(MeetingStatus::Scheduled);
});

it('refuses recording a meeting as held with no minutes', function (): void {
    $actor = ptaActor();
    $guardianId = ptaGuardianId();

    $meeting = app(ScheduleGuardianMeeting::class)->handle(
        $guardianId, null, now()->toDateString(),
        MeetingType::Financial, MeetingRequestedBy::Guardian, (int) $actor->getKey(),
    );

    expect(fn () => app(RecordMeetingOutcome::class)->handle((int) $meeting->getKey(), MeetingStatus::Held))
        ->toThrow(DomainException::class);
});

it('refuses recording an outcome twice', function (): void {
    $actor = ptaActor();
    $guardianId = ptaGuardianId();

    $meeting = app(ScheduleGuardianMeeting::class)->handle(
        $guardianId, null, now()->toDateString(),
        MeetingType::Other, MeetingRequestedBy::School, (int) $actor->getKey(),
    );

    app(RecordMeetingOutcome::class)->handle((int) $meeting->getKey(), MeetingStatus::Held, 'Minutes here.');

    expect(fn () => app(RecordMeetingOutcome::class)->handle((int) $meeting->getKey(), MeetingStatus::Cancelled))
        ->toThrow(DomainException::class);
});

it('schedules a PTA general meeting and records its minutes', function (): void {
    $actor = ptaActor();

    $meeting = app(SchedulePtaMeeting::class)->handle(
        'First-term general assembly', now()->addWeek()->toDateString(), (int) $actor->getKey(),
        'School hall', 'Budget approval; election of new officers.',
    );

    $held = app(RecordPtaMeetingMinutes::class)->handle((int) $meeting->getKey(), 'Budget approved unanimously.', 84);

    expect($held->status)->toBe('held')
        ->and($held->attendee_count)->toBe(84);
});

it('closes the predecessor\'s term when a new officer is appointed to the same office', function (): void {
    $actor = ptaActor();
    $guardianId = ptaGuardianId();

    $first = app(AppointPtaOfficer::class)->handle($guardianId, 'President', '2025-09-01');
    $second = app(AppointPtaOfficer::class)->handle($guardianId, 'President', '2026-09-01');

    expect(PtaOfficer::find($first->getKey())->term_ends_on->toDateString())->toBe('2026-09-01')
        ->and(PtaOfficer::find($second->getKey())->term_ends_on)->toBeNull();
});
