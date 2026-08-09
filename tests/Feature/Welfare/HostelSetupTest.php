<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Actions\SaveHostel;
use App\Modules\Welfare\Actions\SaveRoom;
use App\Modules\Welfare\Domain\HostelGender;
use App\Modules\Welfare\Domain\HostelPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/HostelTestHelpers.php';

uses(RefreshDatabase::class);

// ── Hostels ─────────────────────────────────────────────────────────────

it('creates a hostel through the gate', function () {
    $user = p10HostelManager();

    $hostel = p10Hostel($user, [
        'code' => 'HBA01',
        'name' => 'Heritage Boys Hostel A',
        'gender' => 'boys',
    ]);

    expect($hostel->exists)->toBeTrue()
        ->and($hostel->code)->toBe('HBA01')
        ->and($hostel->gender)->toBe(HostelGender::Boys)
        ->and($hostel->is_active)->toBeTrue();
});

it('refuses hostel creation without hostel.manage', function () {
    p10HostelUser(HostelPermission::VIEW); // signed in, view only

    app(SaveHostel::class)->handle(null, [
        'code' => 'HBX01',
        'name' => 'Hostel X',
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);

it('rejects a duplicate hostel code', function () {
    $user = p10HostelManager();
    p10Hostel($user, ['code' => 'HBB02']);

    expect(fn () => p10Hostel($user, ['code' => 'HBB02']))
        ->toThrow(ValidationException::class);
});

it('rejects an unknown hostel gender', function () {
    $user = p10HostelManager();

    p10Hostel($user, ['gender' => 'coed']);
})->throws(ValidationException::class);

it('rejects a warden user that does not exist', function () {
    $user = p10HostelManager();

    p10Hostel($user, ['warden_user_id' => 999_999]);
})->throws(ValidationException::class);

it('updates a hostel in place and can deactivate it', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user, ['name' => 'Heritage Girls Hostel B', 'gender' => 'girls']);

    $updated = app(SaveHostel::class)->handle((int) $hostel->getKey(), [
        'name' => 'Heritage Girls Hostel B (annex)',
        'is_active' => false,
        'warden_user_id' => (int) $user->getKey(),
    ], p10HostelActor($user));

    expect($updated->name)->toBe('Heritage Girls Hostel B (annex)')
        ->and($updated->is_active)->toBeFalse()
        ->and($updated->gender)->toBe(HostelGender::Girls)
        ->and($updated->warden_user_id)->toBe((int) $user->getKey());
});

// ── Rooms ───────────────────────────────────────────────────────────────

it('creates a room and enforces name uniqueness per hostel at the DB layer', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel, ['name' => 'A-101', 'capacity' => 6]);

    expect($room->name)->toBe('A-101')
        ->and($room->capacity)->toBe(6)
        ->and($room->hostel_id)->toBe((int) $hostel->getKey());

    // The Action refuses the duplicate...
    expect(fn () => app(SaveRoom::class)->handle(null, [
        'hostel_id' => (int) $hostel->getKey(),
        'name' => 'A-101',
        'capacity' => 4,
    ], p10HostelActor($user)))->toThrow(ValidationException::class);

    // ...and so does the schema when the Action is bypassed.
    expect(fn () => DB::table('hostel_rooms')->insert([
        'hostel_id' => $hostel->getKey(),
        'name' => 'A-101',
        'capacity' => 4,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a non-positive room capacity in the Action and the schema', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);

    expect(fn () => app(SaveRoom::class)->handle(null, [
        'hostel_id' => (int) $hostel->getKey(),
        'name' => 'Z-000',
        'capacity' => 0,
    ], p10HostelActor($user)))->toThrow(ValidationException::class);

    // CHECK (capacity >= 1) backs the rule below the Action.
    expect(fn () => DB::table('hostel_rooms')->insert([
        'hostel_id' => $hostel->getKey(),
        'name' => 'Z-000',
        'capacity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses shrinking capacity below the standing bed count', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel, ['capacity' => 4], ['B1', 'B2', 'B3']);

    app(SaveRoom::class)->handle((int) $room->getKey(), [
        'capacity' => 2,
    ], p10HostelActor($user));
})->throws(ValidationException::class);

// ── Beds ────────────────────────────────────────────────────────────────

it('declares beds up to capacity and refuses beyond it', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel, ['capacity' => 3], ['B1', 'B2', 'B3']);

    expect($room->beds()->count())->toBe(3);

    app(SaveBeds::class)->handle((int) $room->getKey(), ['B1', 'B2', 'B3', 'B4'], p10HostelActor($user));
})->throws(ValidationException::class, 'capacity');

it('reconciles the bed set by label: keeps, adds and removes idempotently', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel, ['capacity' => 4], ['B1', 'B2']);

    $originalB1 = $room->beds()->where('label', 'B1')->firstOrFail();

    $beds = app(SaveBeds::class)->handle((int) $room->getKey(), ['B1', 'B3', 'B4'], p10HostelActor($user));

    $labels = array_map(static fn ($bed): string => $bed->label, $beds);
    sort($labels);

    expect($labels)->toBe(['B1', 'B3', 'B4'])
        // B1 kept its identity (history stays attached), B2 is gone.
        ->and($room->beds()->where('label', 'B1')->firstOrFail()->getKey())->toBe($originalB1->getKey())
        ->and($room->beds()->where('label', 'B2')->exists())->toBeFalse();
});

it('rejects duplicate or blank bed labels', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);

    expect(fn () => app(SaveBeds::class)->handle((int) $room->getKey(), ['B1', 'B1'], p10HostelActor($user)))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveBeds::class)->handle((int) $room->getKey(), ['B1', '  '], p10HostelActor($user)))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveBeds::class)->handle((int) $room->getKey(), [], p10HostelActor($user)))
        ->toThrow(ValidationException::class);
});

it('enforces bed label uniqueness per room at the DB layer', function () {
    $user = p10HostelManager();
    $hostel = p10Hostel($user);
    $room = p10HostelRoom($user, $hostel);

    DB::table('hostel_beds')->insert([
        'room_id' => $room->getKey(),
        'label' => 'B1', // already created by the helper
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('refuses bed changes without hostel.manage', function () {
    $manager = p10HostelManager();
    $hostel = p10Hostel($manager);
    $room = p10HostelRoom($manager, $hostel);

    p10HostelUser(HostelPermission::VIEW); // now signed in without manage

    app(SaveBeds::class)->handle((int) $room->getKey(), ['B1'], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);
