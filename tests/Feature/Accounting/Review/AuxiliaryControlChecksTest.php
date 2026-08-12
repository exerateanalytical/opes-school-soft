<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\AuxiliaryControlChecks;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\ControlStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * AR <-> GL and AP <-> GL, docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * These tests SEED A REAL LEDGER. An earlier version asserted inside a
 * `foreach` over the returned checks, which passed while returning an empty
 * collection - it proved nothing at all. Every test below either posts
 * entries first or asserts on an explicitly-empty expectation.
 */
function auxctlUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * @return array{user: User, journal: Journal, period: AccountingPeriod, fiscal_year: FiscalYear, academic_year_id: int}
 */
function auxctlLedger(): array
{
    $user = auxctlUser();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());

    $fiscalYear = FiscalYear::factory()->create([
        'code' => $today->year.strtoupper(str()->random(4)),
        'starts_on' => sprintf('%d-01-01', $today->year),
        'ends_on' => sprintf('%d-12-31', $today->year),
        'status' => FiscalYearStatus::Open,
    ]);

    $period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_month' => $today->copy()->startOfMonth()->toDateString(),
        'starts_on' => $today->copy()->startOfMonth()->toDateString(),
        'ends_on' => $today->copy()->endOfMonth()->toDateString(),
        'status' => AccountingPeriodStatus::Open,
        'is_quarter_end' => false,
    ]);

    // Trigger L6/C3 rejects an entry whose academic_year_id does not match the
    // year derived from its date, so the year must actually span today. The
    // Cameroonian academic year runs September to August.
    $startYear = $today->month >= 9 ? $today->year : $today->year - 1;
    $code = sprintf('%d-%d-%s', $startYear, $startYear + 1, str()->random(6));

    $academicYearId = (int) DB::table('academic_years')->insertGetId([
        'code' => $code,
        'name' => 'Test AY '.$code,
        'starts_on' => sprintf('%d-09-01', $startYear),
        'ends_on' => sprintf('%d-08-31', $startYear + 1),
        'is_current' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'user' => $user,
        'journal' => Journal::factory()->create(),
        'period' => $period,
        'fiscal_year' => $fiscalYear,
        'academic_year_id' => $academicYearId,
    ];
}

/**
 * @param  array{user: User, journal: Journal, period: AccountingPeriod, fiscal_year: FiscalYear, academic_year_id: int}  $ledger
 * @param  list<array<string, mixed>>  $lines
 */
function auxctlPost(array $ledger, int $sequence, array $lines): JournalEntry
{
    $totalDebit = 0;
    $totalCredit = 0;
    foreach ($lines as $line) {
        $totalDebit += (int) ($line['debit'] ?? 0);
        $totalCredit += (int) ($line['credit'] ?? 0);
    }

    $today = Carbon::parse(BusinessDate::today())->toDateString();

    $entry = JournalEntry::query()->create([
        'journal_id' => $ledger['journal']->id,
        'piece_no' => null,
        'date' => $today,
        'value_date' => $today,
        'accounting_period_id' => $ledger['period']->id,
        'fiscal_year_id' => $ledger['fiscal_year']->id,
        'academic_year_id' => $ledger['academic_year_id'],
        'label' => 'Auxiliary control test entry',
        'status' => JournalEntry::STATUS_DRAFT,
        'total_debit' => 0,
        'total_credit' => 0,
        'created_by' => $ledger['user']->id,
    ]);

    foreach ($lines as $i => $line) {
        JournalEntryLine::query()->create(array_merge([
            'journal_entry_id' => $entry->id,
            'sequence' => $i + 1,
            'label' => 'Line '.($i + 1),
        ], $line));
    }

    $entry->forceFill([
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => sprintf('%s/%s/%06d', $ledger['journal']->code, $ledger['fiscal_year']->code, $sequence),
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'posted_by' => $ledger['user']->id,
        'posted_at' => now(),
    ])->save();

    return $entry->fresh() ?? $entry;
}

/** @return array{0: ChartOfAccount, 1: ChartOfAccount} [collective, bank] */
function auxctlAccounts(): array
{
    $collective = ChartOfAccount::factory()->create([
        'is_collective' => true,
        'requires_partner' => true,
        'allowed_partner_types' => ['student'],
        'is_lettrable' => true,
    ]);

    return [$collective, ChartOfAccount::factory()->create()];
}

it('reconciles a collective account against its per-partner detail', function () {
    $ledger = auxctlLedger();
    [$collective, $bank] = auxctlAccounts();

    auxctlPost($ledger, 1, [
        ['account_id' => $collective->id, 'debit' => 50000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 7],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50000],
    ]);

    auxctlPost($ledger, 2, [
        ['account_id' => $collective->id, 'debit' => 25000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 9],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 25000],
    ]);

    $checks = app(AuxiliaryControlChecks::class)->handle();
    $check = $checks->firstWhere('key', 'auxiliary_'.$collective->code);

    // The check must actually exist - a null here means the Action returned
    // nothing and every other assertion in this file would pass vacuously.
    expect($check)->not->toBeNull();
    expect($check->expected)->toBe(75000);
    expect($check->actual)->toBe(75000);
    expect($check->difference)->toBe(0);
    expect($check->status)->toBe(ControlStatus::Reconciled);
});

it('states the axis and the as_of on every check', function () {
    $ledger = auxctlLedger();
    [$collective, $bank] = auxctlAccounts();

    auxctlPost($ledger, 1, [
        ['account_id' => $collective->id, 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 3],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 10000],
    ]);

    $checks = app(AuxiliaryControlChecks::class)->handle(axis: 'academic_year');

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect($check->axis)->toBe('academic_year');
        expect($check->asOf)->toBe(BusinessDate::today());
    }
});

it('excludes a reversed pair from the balance rather than double counting it', function () {
    $ledger = auxctlLedger();
    [$collective, $bank] = auxctlAccounts();

    auxctlPost($ledger, 1, [
        ['account_id' => $collective->id, 'debit' => 40000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 5],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 40000],
    ]);

    // The mirror image, as a reversal posts it. Read through
    // postedLedgerStatuses() the pair nets to zero; read through a bare
    // `status = 'posted'` filter the original would vanish and the account
    // would show -40000.
    auxctlPost($ledger, 2, [
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 40000, 'partner_type' => 'student', 'partner_id' => 5],
        ['account_id' => $bank->id, 'debit' => 40000, 'credit' => 0],
    ]);

    $check = app(AuxiliaryControlChecks::class)->handle()
        ->firstWhere('key', 'auxiliary_'.$collective->code);

    expect($check)->not->toBeNull();
    expect($check->expected)->toBe(0);
    expect($check->status)->toBe(ControlStatus::Reconciled);
});

it('refuses without ledger.view', function () {
    actingAs(auxctlUser(Role::Teacher));

    app(AuxiliaryControlChecks::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
