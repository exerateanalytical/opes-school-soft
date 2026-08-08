<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\CreateFiscalYear;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function fiscalYearUserAs(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('carries no FK to academic_year - 02-accounting §7/C3, a named correction', function () {
    // A Cameroonian school's fiscal year (calendar year) and academic year
    // (roughly Sept-July) do not and cannot coincide. Every financial
    // entity resolves both independently from its own date; FiscalYear
    // itself must not link to one specific AcademicYear.
    expect(Schema::hasColumn('fiscal_years', 'academic_year_id'))->toBeFalse();
});

it('creates a calendar fiscal year with all twelve accounting periods', function () {
    $user = fiscalYearUserAs();
    actingAs($user);

    $year = app(CreateFiscalYear::class)->handle('2026', '2026-01-01', '2026-12-31', false, $user->toAuditActor());

    expect($year->code)->toBe('2026');
    $periods = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->orderBy('starts_on')->get();
    expect($periods)->toHaveCount(12);

    $firstPeriod = assertNotNull($periods->first());
    $lastPeriod = assertNotNull($periods->last());
    expect($firstPeriod->starts_on->toDateString())->toBe('2026-01-01');
    expect($lastPeriod->ends_on->toDateString())->toBe('2026-12-31');

    $quarterEnds = $periods->filter(fn (AccountingPeriod $p) => $p->is_quarter_end)
        ->pluck('period_month')
        ->map(fn ($d) => (int) $d->format('n'))
        ->sort()
        ->values()
        ->all();
    expect($quarterEnds)->toBe([3, 6, 9, 12]);

    $march = assertNotNull($periods->first(fn (AccountingPeriod $p) => (int) $p->period_month->format('n') === 3));
    expect($march->forced_closure_due_on?->toDateString())->toBe('2026-04-30');
});

it('permits an irregular first exercice ending 31 December', function () {
    $user = fiscalYearUserAs();
    actingAs($user);

    $year = app(CreateFiscalYear::class)->handle('2026', '2026-03-15', '2026-12-31', true, $user->toAuditActor());

    $periods = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->orderBy('starts_on')->get();
    // March (partial) through December = 10 periods, never 12: §5.1 always
    // covers the fiscal year's own span, not a fixed count.
    expect($periods)->toHaveCount(10);
    $firstPeriod = assertNotNull($periods->first());
    expect($firstPeriod->starts_on->toDateString())->toBe('2026-03-15');
    expect($firstPeriod->ends_on->toDateString())->toBe('2026-03-31');
});

it('rejects a first exercice that does not end on 31 December', function () {
    $user = fiscalYearUserAs();
    actingAs($user);

    expect(fn () => app(CreateFiscalYear::class)->handle('2026', '2026-03-15', '2026-11-30', true, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'must end on 31 December');
});

it('rejects a first exercice spanning more than 12 months', function () {
    $user = fiscalYearUserAs();
    actingAs($user);

    expect(fn () => app(CreateFiscalYear::class)->handle('2026', '2025-03-15', '2026-12-31', true, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'must not exceed 12 months');
});

it('rejects a non-first-exercice year not starting 1 January', function () {
    $user = fiscalYearUserAs();
    actingAs($user);

    expect(fn () => app(CreateFiscalYear::class)->handle('2026', '2026-02-01', '2026-12-31', false, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'must start on 1 January');
});

it('rejects a second fiscal year that overlaps the first', function () {
    $user = fiscalYearUserAs();
    actingAs($user);
    $create = app(CreateFiscalYear::class);

    $create->handle('2026', '2026-01-01', '2026-12-31', false, $user->toAuditActor());

    // A second attempt still inside 2026: uses is_first_exercice = true to
    // pass the "must start 1 January" rule so the overlap check is the one
    // that actually fires.
    expect(fn () => $create->handle('2026b', '2026-06-01', '2026-12-31', true, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'overlaps');
});

it('rejects a second fiscal year that leaves a gap after the first', function () {
    $user = fiscalYearUserAs();
    actingAs($user);
    $create = app(CreateFiscalYear::class);

    $create->handle('2026', '2026-01-01', '2026-12-31', false, $user->toAuditActor());

    expect(fn () => $create->handle('2028', '2028-01-01', '2028-12-31', false, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'contiguous');
});

it('accepts a second fiscal year starting exactly the day after the first ends', function () {
    $user = fiscalYearUserAs();
    actingAs($user);
    $create = app(CreateFiscalYear::class);

    $create->handle('2026', '2026-01-01', '2026-12-31', false, $user->toAuditActor());
    $next = $create->handle('2027', '2027-01-01', '2027-12-31', false, $user->toAuditActor());

    expect($next->starts_on->toDateString())->toBe('2027-01-01');
    expect(FiscalYear::query()->count())->toBe(2);
});

it('denies fiscal year creation to a user without ledger.configure', function () {
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    actingAs($user);

    expect(fn () => app(CreateFiscalYear::class)->handle('2026', '2026-01-01', '2026-12-31', false, $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);

    expect(FiscalYear::query()->count())->toBe(0);
});

it('resolves the academic year covering a date independently of the fiscal year, via query builder only (§7 C3)', function () {
    \App\Modules\Academics\Models\AcademicYear::factory()->create([
        'code' => '2026-2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
    ]);

    $academicYearId = AccountingPeriod::resolveAcademicYearId('2027-01-15');

    expect($academicYearId)->toBe(\App\Modules\Academics\Models\AcademicYear::query()->where('code', '2026-2027')->value('id'));
});

it('fails loudly when no academic year covers the date, rather than silently returning null', function () {
    expect(fn () => AccountingPeriod::resolveAcademicYearId('1999-01-01'))
        ->toThrow(DomainException::class, 'No academic year covers');
});
