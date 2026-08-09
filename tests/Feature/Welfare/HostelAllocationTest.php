<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\AllocateBed;
use App\Modules\Welfare\Actions\EndHostelAllocation;
use App\Modules\Welfare\Actions\OccupancyReport;
use App\Modules\Welfare\Actions\RecordInspection;
use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Domain\InspectionRating;
use App\Modules\Welfare\Models\HostelAllocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/HostelTestHelpers.php';

uses(RefreshDatabase::class);

// ── Allocation ──────────────────────────────────────────────────────────

it('allocates an active enrollment to a free bed', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bed = $room->beds()->firstOrFail();
    $enrollmentId = p10HostelEnrollmentId();

    $allocation = app(AllocateBed::class)->handle(
        $enrollmentId,
        (int) $bed->getKey(),
        Carbon::parse('2026-09-10'),
        p10HostelActor($user),
    );

    expect($allocation->status)->toBe(AllocationStatus::Active)
        ->and($allocation->enrollment_id)->toBe($enrollmentId)
        ->and($allocation->bed_id)->toBe((int) $bed->getKey());

    // BOTH generated keys carry their ids while active.
    /** @var object{active_enrollment_key: int|string|null, active_bed_key: int|string|null} $raw */
    $raw = DB::table('hostel_allocations')->where('id', $allocation->getKey())
        ->first(['active_enrollment_key', 'active_bed_key']);

    expect((int) $raw->active_enrollment_key)->toBe($enrollmentId)
        ->and((int) $raw->active_bed_key)->toBe((int) $bed->getKey());
});

it('refuses allocation without hostel.manage', function () {
    $manager = p10HostelManager();
    $hostel = p10Hostel($manager);
    $room = p10HostelRoom($manager, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();
    $enrollmentId = p10HostelEnrollmentId();

    p10HostelUser(HostelPermission::VIEW); // now signed in without manage

    app(AllocateBed::class)->handle(
        $enrollmentId, $bedId, Carbon::parse('2026-09-10'), \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

it('rejects an enrollment that is not active', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();

    app(AllocateBed::class)->handle(
        p10HostelWithdrawnEnrollmentId(), $bedId,
        Carbon::parse('2026-09-10'), p10HostelActor($user),
    );
})->throws(DomainException::class, 'ACTIVE enrollment');

it('rejects an inactive bed and an inactive hostel', function () {
    $user = p10HostelManager();

    // Inactive hostel.
    $closed = p10Hostel($user, ['is_active' => false]);
    $closedRoom = p10HostelRoom($user, $closed);
    $closedBedId = (int) $closedRoom->beds()->firstOrFail()->getKey();

    expect(fn () => app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $closedBedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    ))->toThrow(DomainException::class, 'inactive');

    // Inactive bed in an open hostel.
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bed = $room->beds()->firstOrFail();
    $bed->fill(['is_active' => false])->save();

    expect(fn () => app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), (int) $bed->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    ))->toThrow(DomainException::class, 'inactive');
});

it('enforces the hostel gender rule', function () {
    $user = p10HostelManager();
    $girls = p10Hostel($user, ['gender' => 'girls']);
    $room = p10HostelRoom($user, $girls);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();

    // A male student may not board a girls hostel...
    expect(fn () => app(AllocateBed::class)->handle(
        p10HostelEnrollmentId('male'), $bedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    ))->toThrow(DomainException::class, 'girls');

    // ...but a female student may.
    $allocation = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId('female'), $bedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    expect($allocation->status)->toBe(AllocationStatus::Active);
});

it('rejects an occupied bed with a readable message', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();

    app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $bedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $bedId, Carbon::parse('2026-09-11'), p10HostelActor($user),
    );
})->throws(DomainException::class, 'occupied');

it('ends the prior active allocation atomically on a bed move', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $beds = $room->beds()->get()->all();
    $enrollmentId = p10HostelEnrollmentId();

    $first = app(AllocateBed::class)->handle(
        $enrollmentId, (int) $beds[0]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    $second = app(AllocateBed::class)->handle(
        $enrollmentId, (int) $beds[1]->getKey(), Carbon::parse('2026-10-01'), p10HostelActor($user),
    );

    $first->refresh();

    expect($first->status)->toBe(AllocationStatus::Ended)
        ->and($first->ends_on?->toDateString())->toBe('2026-09-30')
        ->and($second->status)->toBe(AllocationStatus::Active)
        ->and(HostelAllocation::query()->where('enrollment_id', $enrollmentId)->active()->count())->toBe(1);
});

it('clamps the prior end date on a same-day move instead of violating the CHECK', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $beds = $room->beds()->get()->all();
    $enrollmentId = p10HostelEnrollmentId();

    $first = app(AllocateBed::class)->handle(
        $enrollmentId, (int) $beds[0]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    app(AllocateBed::class)->handle(
        $enrollmentId, (int) $beds[1]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    $first->refresh();

    expect($first->status)->toBe(AllocationStatus::Ended)
        ->and($first->ends_on?->toDateString())->toBe('2026-09-10');
});

// ── Both DB-level invariants ────────────────────────────────────────────

it('enforces one active allocation per enrollment at the DB layer', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $beds = $room->beds()->get()->all();
    $enrollmentId = p10HostelEnrollmentId();

    app(AllocateBed::class)->handle(
        $enrollmentId, (int) $beds[0]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    // Bypass the Action entirely: the schema itself must refuse a second
    // ACTIVE row for the same ENROLLMENT (NULL-unique active_enrollment_key).
    DB::table('hostel_allocations')->insert([
        'enrollment_id' => $enrollmentId,
        'bed_id' => $beds[1]->getKey(),
        'starts_on' => '2026-11-01',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('enforces one active allocation per bed at the DB layer', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();

    app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $bedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    // Bypass the Action entirely: the schema itself must refuse a second
    // ACTIVE row for the same BED (NULL-unique active_bed_key).
    DB::table('hostel_allocations')->insert([
        'enrollment_id' => p10HostelEnrollmentId(),
        'bed_id' => $bedId,
        'starts_on' => '2026-11-01',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('permits any number of ENDED rows for the same enrollment and bed', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();
    $enrollmentId = p10HostelEnrollmentId();

    foreach (['2026-09-10', '2026-10-01', '2026-11-01'] as $start) {
        app(AllocateBed::class)->handle(
            $enrollmentId, $bedId, Carbon::parse($start), p10HostelActor($user),
        );
    }

    expect(HostelAllocation::query()->where('enrollment_id', $enrollmentId)->count())->toBe(3)
        ->and(HostelAllocation::query()->where('bed_id', $bedId)->active()->count())->toBe(1);
});

// ── Ending ──────────────────────────────────────────────────────────────

it('ends an active allocation, frees the bed, and refuses a double end', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $bedId = (int) $room->beds()->firstOrFail()->getKey();

    $allocation = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $bedId, Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    $ended = app(EndHostelAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-12-15'), p10HostelActor($user),
    );

    expect($ended->status)->toBe(AllocationStatus::Ended)
        ->and($ended->ends_on?->toDateString())->toBe('2026-12-15')
        ->and(DB::table('hostel_allocations')->where('id', $allocation->getKey())->value('active_bed_key'))->toBeNull();

    // The freed bed takes a new resident.
    $next = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), $bedId, Carbon::parse('2026-12-16'), p10HostelActor($user),
    );

    expect($next->status)->toBe(AllocationStatus::Active);

    expect(fn () => app(EndHostelAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-12-16'), p10HostelActor($user),
    ))->toThrow(DomainException::class, 'ACTIVE');
});

it('refuses an end date before the start date', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);

    $allocation = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), (int) $room->beds()->firstOrFail()->getKey(),
        Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    app(EndHostelAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-09-01'), p10HostelActor($user),
    );
})->throws(DomainException::class, 'cannot end before it starts');

// ── Occupancy report ────────────────────────────────────────────────────

it('derives per-hostel and per-room occupancy from active allocations only', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user, ['code' => 'HOC01']);
    $room = p10HostelRoom($user, $hostel, ['name' => 'A-101', 'capacity' => 3], ['B1', 'B2', 'B3']);
    $beds = $room->beds()->get()->all();

    app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), (int) $beds[0]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );
    $second = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), (int) $beds[1]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    $report = app(OccupancyReport::class)->handle((int) $hostel->getKey(), withRooms: true);

    expect($report)->toHaveCount(1)
        ->and($report[0]['code'])->toBe('HOC01')
        ->and($report[0]['rooms'])->toBe(1)
        ->and($report[0]['beds'])->toBe(3)
        ->and($report[0]['occupied'])->toBe(2)
        ->and($report[0]['available'])->toBe(1)
        ->and($report[0]['occupancy_pct'])->toBe(66.67);

    $detail = $report[0]['rooms_detail'] ?? [];

    expect($detail)->toHaveCount(1)
        ->and($detail[0]['room'])->toBe('A-101')
        ->and($detail[0]['occupied'])->toBe(2);

    // Ended allocations drop out of the numbers.
    app(EndHostelAllocation::class)->handle(
        (int) $second->getKey(), Carbon::parse('2026-10-01'), p10HostelActor($user),
    );

    $after = app(OccupancyReport::class)->handle((int) $hostel->getKey());

    expect($after[0]['occupied'])->toBe(1)
        ->and($after[0]['available'])->toBe(2)
        ->and($after[0]['rooms_detail'])->toBeNull();
});

it('refuses the occupancy report without hostel.view', function () {
    p10HostelUser(); // signed in, no abilities

    app(OccupancyReport::class)->handle(null);
})->throws(AuthorizationException::class);

// ── Inspections ─────────────────────────────────────────────────────────

it('records and resolves an inspection', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);

    $inspection = app(RecordInspection::class)->handle(
        (int) $hostel->getKey(),
        Carbon::parse('2026-10-05'),
        InspectionRating::Poor,
        ['room_id' => (int) $room->getKey(), 'findings' => 'Broken window latch in the corner.'],
        p10HostelActor($user),
    );

    expect($inspection->rating)->toBe(InspectionRating::Poor)
        ->and($inspection->room_id)->toBe((int) $room->getKey())
        ->and($inspection->inspected_by)->toBe((int) $user->getKey())
        ->and($inspection->resolved_at)->toBeNull()
        ->and($inspection->rating->needsFollowUp())->toBeTrue();

    $resolved = app(RecordInspection::class)->resolve((int) $inspection->getKey(), p10HostelActor($user));

    expect($resolved->resolved_at)->not->toBeNull();

    // A second resolve is refused.
    expect(fn () => app(RecordInspection::class)->resolve((int) $inspection->getKey(), p10HostelActor($user)))
        ->toThrow(DomainException::class, 'already resolved');
});

it('rejects an inspection room that belongs to another hostel', function () {
    $user = p10HostelManager();
    $hostelA = p10Hostel($user);
    $hostelB = p10Hostel($user);
    $foreignRoom = p10HostelRoom($user, $hostelB);

    app(RecordInspection::class)->handle(
        (int) $hostelA->getKey(),
        Carbon::parse('2026-10-05'),
        InspectionRating::Good,
        ['room_id' => (int) $foreignRoom->getKey()],
        p10HostelActor($user),
    );
})->throws(DomainException::class, 'does not belong');

it('refuses recording an inspection without hostel.manage', function () {
    $manager = p10HostelManager();
    $hostel = p10Hostel($manager);

    p10HostelUser(HostelPermission::VIEW);

    app(RecordInspection::class)->handle(
        (int) $hostel->getKey(), Carbon::parse('2026-10-05'),
        InspectionRating::Good, [], \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

// ── Bed removal vs history ──────────────────────────────────────────────

it('refuses removing a bed that carries allocation history', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);
    $beds = $room->beds()->get()->all();

    $allocation = app(AllocateBed::class)->handle(
        p10HostelEnrollmentId(), (int) $beds[0]->getKey(), Carbon::parse('2026-09-10'), p10HostelActor($user),
    );

    // Even after the stay ENDS, the history pins the bed row.
    app(EndHostelAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-10-01'), p10HostelActor($user),
    );

    app(SaveBeds::class)->handle((int) $room->getKey(), ['B2'], p10HostelActor($user));
})->throws(DomainException::class, 'history');
