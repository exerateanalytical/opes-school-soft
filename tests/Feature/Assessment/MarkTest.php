<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\SaveMark;
use App\Modules\Assessment\Actions\SaveMarkBatch;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Database\Factories\MarkFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelBack;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

/**
 * The Exams Officer is the actor for the happy paths: 00-core 9.1 has that
 * office "fill gaps in entry", so it is the one role that enters marks without
 * a per-allocation assignment. A plain Teacher is used for the T22 refusals,
 * which is exactly the distinction under test.
 */
if (! function_exists('markUserAs')) {
    function markUserAs(Role $role, string $name = 'Exams Officer'): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('stores a score as exact decimal thousandths, never a float', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 1);
    $mark = Mark::query()->findOrFail($scenario['marks'][0]);

    app(SaveMark::class)->handle($mark, MarkState::Scored, '13.333');

    $mark->refresh();

    expect($mark->score)->toBe('13.333')
        ->and($mark->scoreValue()?->thousandths())->toBe(13_333)
        ->and($mark->scoreValue()?->toDisplayString())->toBe('13.33');

    // The column, not the cast: a FLOAT would not round-trip 13.333.
    expect(DB::table('marks')->where('id', $mark->getKey())->value('score'))->toBe('13.333');
});

it('keeps state and workflow_state as two independent axes', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 2);

    // A validated absence and a draft 14 - both inexpressible in v1's single
    // status column (7.1).
    DB::table('marks')->where('id', $scenario['marks'][0])->update([
        'state' => MarkState::AbsentJustified->value,
        'workflow_state' => WorkflowState::Validated->value,
    ]);

    $absence = Mark::query()->findOrFail($scenario['marks'][0]);
    $draft = Mark::query()->findOrFail($scenario['marks'][1]);

    app(SaveMark::class)->handle($draft, MarkState::Scored, '14.000');
    $draft->refresh();

    expect($absence->state)->toBe(MarkState::AbsentJustified)
        ->and($absence->workflow_state)->toBe(WorkflowState::Validated)
        ->and($absence->score)->toBeNull()
        ->and($draft->state)->toBe(MarkState::Scored)
        ->and($draft->workflow_state)->toBe(WorkflowState::Draft)
        ->and($draft->score)->toBe('14.000');
});

it('makes a duplicate materialisation row impossible, so opening a period twice is idempotent', function () {
    $scenario = MarkFactory::scenario(students: 1);
    $row = (array) DB::table('marks')->where('id', $scenario['marks'][0])->first();

    unset($row['id']);

    expect(fn () => DB::table('marks')->insert($row))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(DB::table('marks')->count())->toBe(1);
});

it('refuses a scored mark with no number and a number parked under a non-scored state', function () {
    $scenario = MarkFactory::scenario(students: 1);

    expect(fn () => DB::table('marks')->where('id', $scenario['marks'][0])->update([
        'state' => MarkState::Scored->value,
        'score' => null,
    ]))->toThrow(Illuminate\Database\QueryException::class);

    expect(fn () => DB::table('marks')->where('id', $scenario['marks'][0])->update([
        'state' => MarkState::AbsentUnjustified->value,
        'score' => '0.000',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects a mark above the effective maximum', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 1);
    $mark = Mark::query()->findOrFail($scenario['marks'][0]);

    expect(fn () => app(SaveMark::class)->handle($mark, MarkState::Scored, '21.000'))
        ->toThrow(DomainException::class, 'exceeds the maximum');
});

// ---------------------------------------------------------------------------
// T16 - optimistic locking.
// ---------------------------------------------------------------------------

it('rejects the second of two concurrent saves, naming the conflicting value and the actor who set it', function () {
    $seeded = markUserAs(Role::ExamsOfficer, 'Awono Marie');
    $second = User::factory()->create(['name' => 'Ngu Peter']);
    $second->assignRole(Role::ExamsOfficer->value);

    $scenario = MarkFactory::scenario(students: 1);
    $markId = $scenario['marks'][0];

    // Both teachers open the same grid and both hold version 1.
    $asAwono = Mark::query()->findOrFail($markId);
    $asPeter = Mark::query()->findOrFail($markId);

    expect($asAwono->version)->toBe(1)->and($asPeter->version)->toBe(1);

    actingAs($seeded);
    expect(app(SaveMark::class)->handle($asAwono, MarkState::Scored, '14.000'))->toBeNull();

    // Peter saves the stale row he still has open. Two real updates, the
    // second carrying version 1 against a row now at version 2.
    actingAs($second->fresh() ?? $second);
    $conflict = app(SaveMark::class)->handle($asPeter, MarkState::Scored, '17.000');

    if ($conflict === null) {
        throw new RuntimeException('The stale save was accepted; the optimistic lock did not hold.');
    }

    expect($conflict['expected_version'])->toBe(1)
        ->and($conflict['current_version'])->toBe(2)
        ->and($conflict['their_score'])->toBe('14.000')
        ->and($conflict['their_actor_id'])->toBe((int) $seeded->getKey())
        ->and($conflict['their_actor_name'])->toBe('Awono Marie')
        ->and($conflict['message'])->toContain('Awono Marie')
        ->and($conflict['message'])->toContain('14.000');

    // Nothing was silently overwritten.
    expect(DB::table('marks')->where('id', $markId)->value('score'))->toBe('14.000');
    expect((int) DB::table('marks')->where('id', $markId)->value('version'))->toBe(2);
});

// ---------------------------------------------------------------------------
// T21 - one request, one transaction, for a 62-row grid.
// ---------------------------------------------------------------------------

it('saves 62 changed rows in one round trip - one select, no per-row read, one audit entry', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 62);

    $rows = [];

    foreach ($scenario['marks'] as $i => $markId) {
        $rows[] = [
            'mark_id' => $markId,
            'version' => 1,
            'state' => MarkState::Scored->value,
            'score' => sprintf('%d.000', 5 + ($i % 15)),
        ];
    }

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $result = app(SaveMarkBatch::class)->handle(
        subjectAllocationId: $scenario['allocation'],
        assessmentPeriodId: $scenario['period'],
        rows: $rows,
    );

    expect($result['saved'])->toHaveCount(62)
        ->and($result['conflicts'])->toBe([]);

    $selectsOnMarks = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '`marks`'),
    ));
    $updatesOnMarks = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'update `marks`'),
    ));
    $auditInserts = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'insert into `audit_logs`'),
    ));

    // ONE read of the grid, not 62: the N+1 is the whole reason 17 forbids a
    // round trip per cell.
    expect($selectsOnMarks)->toHaveCount(1)
        ->and($updatesOnMarks)->toHaveCount(62)
        ->and($auditInserts)->toHaveCount(1);

    expect(AuditLog::query()->where('module', 'Assessment')->count())->toBe(1);
});

it('is atomic: one bad row in a batch of 62 persists nothing', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 62);

    $rows = [];

    foreach ($scenario['marks'] as $i => $markId) {
        $rows[] = [
            'mark_id' => $markId,
            'version' => 1,
            'state' => MarkState::Scored->value,
            // Row 40 is out of range for a /20 component.
            'score' => $i === 40 ? '99.000' : '12.000',
        ];
    }

    expect(fn () => app(SaveMarkBatch::class)->handle(
        subjectAllocationId: $scenario['allocation'],
        assessmentPeriodId: $scenario['period'],
        rows: $rows,
    ))->toThrow(DomainException::class);

    expect(DB::table('marks')->whereNotNull('score')->count())->toBe(0);
    expect(DB::table('marks')->where('version', '>', 1)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// T18 - the entry window is Africa/Douala, evaluated at transaction start.
// ---------------------------------------------------------------------------

it('accepts a 00:30 local save on the closing date, and refuses it the following local day', function () {
    actingAs(markUserAs(Role::ExamsOfficer));

    // 23:30Z is 00:30 the NEXT day in Africa/Douala (UTC+1, no DST). The two
    // calendars disagree at this instant, which is precisely the hour
    // 00-core 7.5 says a UTC evaluation gets wrong.
    travelTo(Carbon::parse('2026-11-29 23:30:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-11-30')
        ->and(Carbon::now('UTC')->toDateString())->toBe('2026-11-29');

    $open = MarkFactory::scenario(
        students: 1,
        opensAt: '2026-09-01 00:00:00',
        closesAt: '2026-11-30 00:00:00',
    );

    $mark = Mark::query()->findOrFail($open['marks'][0]);

    expect(app(SaveMark::class)->handle($mark, MarkState::Scored, '15.000'))->toBeNull();
    expect(DB::table('marks')->where('id', $mark->getKey())->value('score'))->toBe('15.000');

    // The same instant, a window that closed on the 29th. A UTC evaluation
    // would still read "29 November" and wrongly let this through.
    DB::table('assessment_periods')
        ->where('id', $open['period'])
        ->update(['marks_entry_closes_at' => '2026-11-29 00:00:00']);

    $mark->refresh();

    expect(fn () => app(SaveMark::class)->handle($mark, MarkState::Scored, '16.000'))
        ->toThrow(DomainException::class, 'closed on 29/11/2026');

    travelBack();
});

it('refuses entry before the window opens, printing the window', function () {
    actingAs(markUserAs(Role::ExamsOfficer));
    travelTo(Carbon::parse('2026-08-15 09:00:00', BusinessDate::TIMEZONE));

    $scenario = MarkFactory::scenario(
        students: 1,
        opensAt: '2026-09-01 08:00:00',
        closesAt: '2026-11-30 00:00:00',
    );

    $mark = Mark::query()->findOrFail($scenario['marks'][0]);

    expect(fn () => app(SaveMark::class)->handle($mark, MarkState::Scored, '15.000'))
        ->toThrow(DomainException::class, 'opens on 01/09/2026 08:00');

    travelBack();
});

// ---------------------------------------------------------------------------
// T22 - deny by default, on the READ as well as the write.
// ---------------------------------------------------------------------------

it('refuses a Teacher the WRITE of marks for an allocation they are neither assigned nor delegated', function () {
    actingAs(markUserAs(Role::Teacher, 'Mr Tabi'));
    $scenario = MarkFactory::scenario(students: 1);
    $mark = Mark::query()->findOrFail($scenario['marks'][0]);

    expect(fn () => app(SaveMark::class)->handle($mark, MarkState::Scored, '15.000'))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('marks')->where('id', $mark->getKey())->value('score'))->toBeNull();
});

it('refuses a Teacher the READ of marks for an allocation they are neither assigned nor delegated', function () {
    $teacher = markUserAs(Role::Teacher, 'Mr Tabi');
    $scenario = MarkFactory::scenario(students: 3);

    DB::table('marks')->update(['state' => MarkState::Scored->value, 'score' => '15.000']);

    actingAs($teacher);

    // A teacher reading another class's grid is a privacy breach, not merely
    // an authorisation miss - so the read is gated by the same rule as the
    // write, and returns nothing rather than "everything, minus a button".
    expect(Mark::query()->enterableBy()->count())->toBe(0);
    expect(Mark::mayEnter($scenario['allocation']))->toBeFalse();

    // The same query for the exams office returns the grid, which proves the
    // zero above is the gate and not an empty table.
    actingAs(markUserAs(Role::ExamsOfficer, 'Mme Fotso'));
    expect(Mark::query()->enterableBy()->count())->toBe(3);
});

it('refuses a role holding neither marks.enter nor an assignment', function () {
    actingAs(markUserAs(Role::Bursar, 'Bursar'));
    $scenario = MarkFactory::scenario(students: 1);

    expect(Mark::mayEnter($scenario['allocation']))->toBeFalse();
    expect(Mark::query()->enterableBy()->count())->toBe(0);
});
