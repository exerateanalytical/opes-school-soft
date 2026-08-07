<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Academics\Actions\SetCurrentAcademicYear;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('academicsUserAs')) {
    /**
     * Local helper, deliberately NOT the Identity suite's userAs(): Pest test
     * files share one global function namespace, so importing or re-declaring
     * that name here would collide. Tests are not bound by module boundaries
     * (only app/ code is), so creating the User directly is fine.
     */
    function academicsUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(CreateAcademicYear::PERMISSION, 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(CreateAcademicYear::PERMISSION);
        }

        return $user->fresh() ?? $user;
    }
}

it('creates the first academic year without a contiguity constraint', function () {
    $user = academicsUserAs();
    actingAs($user);

    $year = app(CreateAcademicYear::class)->handle(
        code: '2026-2027',
        name: 'Academic Year 2026/2027',
        startsOn: '2026-09-01',
        endsOn: '2027-08-31',
        actor: $user->toAuditActor(),
    );

    expect($year->exists)->toBeTrue();
    expect($year->code)->toBe('2026-2027');
    expect($year->is_current)->toBeFalse();
    expect(AuditLog::query()->where('module', 'Academics')->where('action', 'created')->count())->toBe(1);
});

it('rejects a second year that leaves a gap after the first', function () {
    $user = academicsUserAs();
    actingAs($user);
    $create = app(CreateAcademicYear::class);

    $create->handle('2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor());

    // 2027-09-02 leaves 2027-09-01 belonging to no academic year - exactly
    // the "August belongs to nobody" defect 00-core 8 exists to prevent.
    expect(fn () => $create->handle('2027-2028', 'AY 2027/2028', '2027-09-02', '2028-08-31', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'must start on 2027-09-01');

    expect(AcademicYear::query()->count())->toBe(1);
});

it('rejects a second year that overlaps the first', function () {
    $user = academicsUserAs();
    actingAs($user);
    $create = app(CreateAcademicYear::class);

    $create->handle('2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor());

    expect(fn () => $create->handle('2027-2028', 'AY 2027/2028', '2027-08-01', '2028-08-31', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'contiguous');
});

it('accepts a second year starting exactly the day after the first ends', function () {
    $user = academicsUserAs();
    actingAs($user);
    $create = app(CreateAcademicYear::class);

    $create->handle('2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor());
    $next = $create->handle('2027-2028', 'AY 2027/2028', '2027-09-01', '2028-08-31', $user->toAuditActor());

    expect($next->starts_on->toDateString())->toBe('2027-09-01');
    expect(AcademicYear::query()->count())->toBe(2);
});

it('rejects a year that ends on or before its start', function () {
    $user = academicsUserAs();
    actingAs($user);

    expect(fn () => app(CreateAcademicYear::class)->handle(
        '2026-2027', 'AY 2026/2027', '2026-09-01', '2026-09-01', $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'must end after it starts');
});

it('moves the current flag from the previous year to the new one atomically', function () {
    $user = academicsUserAs();
    actingAs($user);
    $create = app(CreateAcademicYear::class);

    $first = $create->handle('2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor());
    $second = $create->handle('2027-2028', 'AY 2027/2028', '2027-09-01', '2028-08-31', $user->toAuditActor());

    $setCurrent = app(SetCurrentAcademicYear::class);
    $setCurrent->handle($first->id, $user->toAuditActor());
    $setCurrent->handle($second->id, $user->toAuditActor());

    expect(AcademicYear::query()->findOrFail($first->id)->is_current)->toBeFalse();
    expect(AcademicYear::query()->findOrFail($second->id)->is_current)->toBeTrue();
    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1);
});

it('setting the already-current year is a no-op that keeps exactly one current', function () {
    $user = academicsUserAs();
    actingAs($user);

    $year = app(CreateAcademicYear::class)
        ->handle('2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor());

    $setCurrent = app(SetCurrentAcademicYear::class);
    $setCurrent->handle($year->id, $user->toAuditActor());
    $setCurrent->handle($year->id, $user->toAuditActor());

    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1);
});

it('makes two current years a database error, not silent corruption', function () {
    // The schema's stored generated column (current_key = 1 only when
    // is_current = 1, under a UNIQUE index) is the backstop for any code path
    // that bypasses SetCurrentAcademicYear - a race, a raw import, a future
    // bug. MySQL rejects the second row; nothing ends up half-right.
    AcademicYear::factory()->current()->create();

    expect(fn () => AcademicYear::factory()->current()->create())
        ->toThrow(QueryException::class);

    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1);
});

it('rejects a duplicate year code at the database', function () {
    AcademicYear::factory()->create(['code' => '2026-2027']);

    expect(fn () => AcademicYear::factory()->create(['code' => '2026-2027']))
        ->toThrow(QueryException::class);
});

it('denies year creation to a user without academics.manage', function () {
    $user = academicsUserAs(withPermission: false);
    actingAs($user);

    expect(fn () => app(CreateAcademicYear::class)->handle(
        '2026-2027', 'AY 2026/2027', '2026-09-01', '2027-08-31', $user->toAuditActor(),
    ))->toThrow(AuthorizationException::class);

    expect(AcademicYear::query()->count())->toBe(0);
});

it('denies setting the current year to a user without academics.manage', function () {
    $year = AcademicYear::factory()->create();
    $user = academicsUserAs(withPermission: false);
    actingAs($user);

    expect(fn () => app(SetCurrentAcademicYear::class)->handle($year->id, $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});
