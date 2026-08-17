<?php

declare(strict_types=1);

// The Take Attendance screen renders a suspended student's row as a hidden
// input with a "recorded as suspended" note rather than radios (§9.5 — it is
// a roster fact, not a teacher's choice). Whatever the component seeds into
// `marks` for that row is therefore exactly what Save posts, with nothing on
// screen able to correct it. It seeded `present`, so every suspended student
// was silently recorded present: no exception row, present_count inflated,
// and the double-punishment rule left with nothing to exclude.

use App\Modules\Attendance\Livewire\TakeRegister;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Students\Domain\EnrollmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

it('records a suspended student as suspended, not present', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    $enrollments[0]->update(['status' => EnrollmentStatus::Suspended]);
    $suspendedId = (int) $enrollments[0]->getKey();

    actingAs(phase8F2Teacher($fixture));

    Livewire::test(TakeRegister::class)
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('date', '2026-09-07')
        ->assertSet("marks.{$suspendedId}", 'suspended')
        ->call('save')
        ->assertHasNoErrors();

    $register = AttendanceRegister::query()->firstOrFail();

    expect($register->expected_count)->toBe(3)
        ->and($register->present_count)->toBe(2);

    $record = AttendanceRecord::query()->where('enrollment_id', $suspendedId)->firstOrFail();

    expect($record->status->value)->toBe('suspended');
});

it('does not let Mark All Present overwrite a suspended row', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    $enrollments[0]->update(['status' => EnrollmentStatus::Suspended]);
    $suspendedId = (int) $enrollments[0]->getKey();

    actingAs(phase8F2Teacher($fixture));

    Livewire::test(TakeRegister::class)
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('date', '2026-09-07')
        ->call('markAllPresent')
        ->assertSet("marks.{$suspendedId}", 'suspended')
        ->call('clearAll')
        ->assertSet("marks.{$suspendedId}", 'suspended');
});
