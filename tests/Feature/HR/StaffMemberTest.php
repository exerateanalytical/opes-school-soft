<?php

declare(strict_types=1);

use App\Modules\HR\Models\StaffMember;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('creates a staff member', function () {
    $staff = StaffMember::factory()->create([
        'staff_no' => 'STF-0001',
        'first_name' => 'Ngwa',
        'last_name' => 'Bertrand',
    ]);

    assertDatabaseHas('staff_members', ['staff_no' => 'STF-0001', 'status' => 'active']);

    expect($staff->fullName())->toBe('Ngwa Bertrand');
});

it('refuses a duplicate staff number', function () {
    StaffMember::factory()->create(['staff_no' => 'STF-0001']);

    expect(fn () => StaffMember::factory()->create(['staff_no' => 'STF-0001']))
        ->toThrow(QueryException::class);
});

it('lets several staff members have no e-mail address', function () {
    // The unique index on `email` must not turn "no address on file" into a
    // one-per-school privilege: MySQL treats every NULL as distinct.
    StaffMember::factory()->create(['email' => null]);
    StaffMember::factory()->create(['email' => null]);

    expect(StaffMember::query()->whereNull('email')->count())->toBe(2);
});

it('scopes to active staff', function () {
    StaffMember::factory()->create();
    StaffMember::factory()->inactive()->create();

    expect(StaffMember::query()->active()->count())->toBe(1);
});
