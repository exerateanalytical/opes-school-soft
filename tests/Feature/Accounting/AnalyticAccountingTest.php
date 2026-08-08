<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\AllocateLineAnalytics;
use App\Modules\Accounting\Actions\ConfigureAnalyticAxis;
use App\Modules\Accounting\Actions\ConfigureAnalyticValue;
use App\Modules\Accounting\Actions\VerifyAnalyticAllocations;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\JournalEntryLineAnalytic;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('analyticUserAs')) {
    function analyticUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.post', 'web');
        Permission::findOrCreate('ledger.view', 'web');
        Permission::findOrCreate('ledger.configure', 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('ledger.post', 'ledger.view', 'ledger.configure');
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('analyticAcademicYearId')) {
    function analyticAcademicYearId(Carbon $anchor): int
    {
        $startYear = $anchor->month >= 9 ? $anchor->year : $anchor->year - 1;
        $code = sprintf('%d-%d-%s', $startYear, $startYear + 1, str()->random(6));

        return (int) DB::table('academic_years')->insertGetId([
            'code' => $code,
            'name' => 'Test AY '.$code,
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-08-31', $startYear + 1),
            'is_current' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('analyticScaffold')) {
    /**
     * Fiscal year + open period + journal + academic year around today.
     *
     * @return array{fiscalYear: FiscalYear, period: AccountingPeriod, journal: Journal, academicYearId: int, today: Carbon}
     */
    function analyticScaffold(): array
    {
        $today = Carbon::parse(BusinessDate::today());
        $academicYearId = analyticAcademicYearId($today);

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
            'status' => 'open',
            'is_quarter_end' => false,
        ]);

        return [
            'fiscalYear' => $fiscalYear,
            'period' => $period,
            'journal' => Journal::factory()->create(),
            'academicYearId' => $academicYearId,
            'today' => $today,
        ];
    }
}

if (! function_exists('analyticPostEntry')) {
    /**
     * Same draft-then-flip order as the other accounting suites: L3's
     * trigger forbids line inserts once the parent is posted, so lines go
     * in while the entry is still draft.
     *
     * @param  array{fiscalYear: FiscalYear, period: AccountingPeriod, journal: Journal, academicYearId: int, today: Carbon}  $scaffold
     * @param  list<array<string, mixed>>  $lines
     */
    function analyticPostEntry(array $scaffold, array $lines, User $actor): JournalEntry
    {
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $line) {
            $totalDebit += $line['debit'] ?? 0;
            $totalCredit += $line['credit'] ?? 0;
        }

        $entry = JournalEntry::query()->create([
            'journal_id' => $scaffold['journal']->id,
            'piece_no' => null,
            'date' => $scaffold['today']->toDateString(),
            'value_date' => $scaffold['today']->toDateString(),
            'accounting_period_id' => $scaffold['period']->id,
            'fiscal_year_id' => $scaffold['fiscalYear']->id,
            'academic_year_id' => $scaffold['academicYearId'],
            'label' => 'Analytic test entry',
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
            'created_by' => $actor->id,
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
            'piece_no' => sprintf('%s/%s/%06d', $scaffold['journal']->code, $scaffold['fiscalYear']->code, random_int(1, 999999)),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'posted_by' => $actor->id,
            'posted_at' => now(),
        ])->save();

        return $entry->fresh() ?? $entry;
    }
}

if (! function_exists('analyticExpenseAccount')) {
    /** A seeded, postable P&L leaf (class 6) - requires_analytic per §12.3. */
    function analyticExpenseAccount(): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('account_class', 6)
            ->where('is_postable', true)
            ->orderBy('code')
            ->firstOrFail();
    }
}

if (! function_exists('analyticSectionValues')) {
    /**
     * @return list<AnalyticValue>
     */
    function analyticSectionValues(int $count): array
    {
        $axis = AnalyticAxis::query()->where('code', 'SECTION')->firstOrFail();

        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = AnalyticValue::factory()->create(['analytic_axis_id' => $axis->id]);
        }

        return $values;
    }
}

it('seeds the four axes and the ACTIVITY members, and flags class 6/7 as requires_analytic', function () {
    expect(AnalyticAxis::query()->pluck('code')->sort()->values()->all())
        ->toBe(['ACTIVITY', 'PROJECT', 'SECTION', 'SITE']);

    $activity = AnalyticAxis::query()->where('code', 'ACTIVITY')->firstOrFail();
    expect($activity->is_mandatory)->toBeTrue();
    expect($activity->applies_to_classes)->toBe([6, 7]);

    expect(
        AnalyticValue::query()->where('analytic_axis_id', $activity->id)->pluck('code')->sort()->values()->all()
    )->toBe(['ADMINISTRATION', 'BOARDING', 'CANTEEN', 'LIBRARY', 'TEACHING', 'TRANSPORT']);

    // SECTION members are per-school configuration, not seeded (§12.2).
    $section = AnalyticAxis::query()->where('code', 'SECTION')->firstOrFail();
    expect(AnalyticValue::query()->where('analytic_axis_id', $section->id)->count())->toBe(0);

    // §12.3: requires_analytic defaults true on the P&L, false elsewhere.
    expect(analyticExpenseAccount()->requires_analytic)->toBeTrue();
    expect(
        ChartOfAccount::query()->where('account_class', 5)->where('requires_analytic', true)->count()
    )->toBe(0);
});

it('conserves an awkward magnitude exactly - AN-1 by construction on a posted line', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    // 100 003 split 33.3333% / 33.3333% / 33.3334% has no clean split -
    // naive proportional arithmetic drifts; the Allocator must not.
    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 100_003, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 100_003],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    [$a, $b, $c] = analyticSectionValues(3);

    // The entry is POSTED - allocating analytics afterwards is by design:
    // the L3 line-lock trigger freezes journal_entry_lines itself, not
    // this child table.
    $rows = app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $a->code, 'shareBp' => 333_333],
        ['valueCode' => $b->code, 'shareBp' => 333_333],
        ['valueCode' => $c->code, 'shareBp' => 333_334],
    ], $user->toAuditActor());

    expect($rows)->toHaveCount(3);

    $amounts = array_map(static fn (JournalEntryLineAnalytic $row): int => $row->amount, $rows);

    expect(array_sum($amounts))->toBe(100_003);
    foreach ($amounts as $amount) {
        expect($amount)->toBeGreaterThan(0);
    }

    // And the verifier agrees: no AN-1/AN-2 violations for this line.
    $violations = app(VerifyAnalyticAllocations::class)->handle($scaffold['fiscalYear']->id);
    expect(array_filter($violations, fn (array $v): bool => in_array($v['invariant'], ['AN-1', 'AN-2'], true)))
        ->toBe([]);
});

it('carries the credit sign - a credit line allocates negative signed amounts summing to -magnitude', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $revenue = ChartOfAccount::query()
        ->where('account_class', 7)->where('is_postable', true)->orderBy('code')->firstOrFail();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $offset->id, 'debit' => 70_001, 'credit' => 0],
        ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 70_001],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $revenue->id)->firstOrFail();

    [$a, $b] = analyticSectionValues(2);

    $rows = app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $a->code, 'shareBp' => 500_000],
        ['valueCode' => $b->code, 'shareBp' => 500_000],
    ], $user->toAuditActor());

    $amounts = array_map(static fn (JournalEntryLineAnalytic $row): int => $row->amount, $rows);

    expect(array_sum($amounts))->toBe(-70_001);
    expect(abs(array_sum($amounts)))->toBe(70_001);
});

it('rejects an allocation whose shares do not sum to 100% - AN-2', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 50_000, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 50_000],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    [$a, $b, $c] = analyticSectionValues(3);

    expect(fn () => app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $a->code, 'shareBp' => 333_333],
        ['valueCode' => $b->code, 'shareBp' => 333_333],
        ['valueCode' => $c->code, 'shareBp' => 333_333],
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'AN-2');

    expect(JournalEntryLineAnalytic::query()->where('journal_entry_line_id', $line->id)->count())->toBe(0);
});

it('reports AN-3 for an unallocated mandatory axis and passes once every mandatory axis is covered', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 30_000, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 30_000],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    // Refusal: nothing allocated yet, so BOTH mandatory axes (SECTION,
    // ACTIVITY) are reported for the requires_analytic class-6 line - and
    // the class-9 offset line (requires_analytic = false) is not.
    $violations = app(VerifyAnalyticAllocations::class)->handle($scaffold['fiscalYear']->id);
    $an3 = array_values(array_filter($violations, fn (array $v): bool => $v['invariant'] === 'AN-3'));

    expect(array_column($an3, 'journal_entry_line_id'))->toBe([$line->id, $line->id]);

    // Pass: cover both mandatory axes.
    [$section] = analyticSectionValues(1);

    app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $section->code, 'shareBp' => 1_000_000],
    ], $user->toAuditActor());
    app(AllocateLineAnalytics::class)->handle($line->id, 'ACTIVITY', [
        ['valueCode' => 'TEACHING', 'shareBp' => 1_000_000],
    ], $user->toAuditActor());

    expect(app(VerifyAnalyticAllocations::class)->handle($scaffold['fiscalYear']->id))->toBe([]);
});

it('refuses to archive a value referenced by an unclosed fiscal year - AN-4 - and archives it once the year closes', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 10_000, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 10_000],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    [$value] = analyticSectionValues(1);

    app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $value->code, 'shareBp' => 1_000_000],
    ], $user->toAuditActor());

    expect(fn () => app(ConfigureAnalyticValue::class)->handle($value->id, ['is_archived' => true], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'AN-4');

    // Once the fiscal year is closed the reference no longer blocks.
    $scaffold['fiscalYear']->forceFill(['status' => FiscalYearStatus::Closed])->save();

    $archived = app(ConfigureAnalyticValue::class)->handle($value->id, ['is_archived' => true], $user->toAuditActor());
    expect($archived->is_archived)->toBeTrue();
});

it('builds a hierarchy - a child value under a parent on the same axis, never across axes', function () {
    $user = analyticUserAs();
    actingAs($user);

    $section = AnalyticAxis::query()->where('code', 'SECTION')->firstOrFail();
    $activity = AnalyticAxis::query()->where('code', 'ACTIVITY')->firstOrFail();

    $parent = app(ConfigureAnalyticValue::class)->handle(null, [
        'analytic_axis_id' => $section->id,
        'code' => 'SECONDARY',
        'name' => 'Secondary',
        'name_fr' => 'Secondaire',
    ], $user->toAuditActor());

    $child = app(ConfigureAnalyticValue::class)->handle(null, [
        'analytic_axis_id' => $section->id,
        'code' => 'SECONDARY-1',
        'name' => 'Secondary first cycle',
        'name_fr' => 'Premier cycle secondaire',
        'parent_id' => $parent->id,
    ], $user->toAuditActor());

    expect($child->parent_id)->toBe($parent->id);
    expect($parent->refresh()->children()->count())->toBe(1);

    // A parent from another axis is refused.
    expect(fn () => app(ConfigureAnalyticValue::class)->handle(null, [
        'analytic_axis_id' => $activity->id,
        'code' => 'BADCHILD',
        'name' => 'Bad child',
        'name_fr' => 'Mauvais enfant',
        'parent_id' => $parent->id,
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'same axis');

    // And so is a duplicate code on the same axis - UNIQUE(axis, code).
    expect(fn () => app(ConfigureAnalyticValue::class)->handle(null, [
        'analytic_axis_id' => $section->id,
        'code' => 'SECONDARY',
        'name' => 'Duplicate',
        'name_fr' => 'Doublon',
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'already exists');
});

it('catches a hand-inserted AN-1/AN-2 violation the Action could never produce', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 40_000, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 40_000],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    [$value] = analyticSectionValues(1);

    // Bypass AllocateLineAnalytics: wrong amount AND short shares.
    JournalEntryLineAnalytic::factory()->create([
        'journal_entry_line_id' => $line->id,
        'analytic_axis_id' => $value->analytic_axis_id,
        'analytic_value_id' => $value->id,
        'amount' => 39_999,
        'share_bp' => 900_000,
    ]);

    $violations = app(VerifyAnalyticAllocations::class)->handle($scaffold['fiscalYear']->id);
    $invariants = array_column($violations, 'invariant', null);

    expect($invariants)->toContain('AN-1');
    expect($invariants)->toContain('AN-2');
});

it('replaces an axis allocation atomically on re-allocation', function () {
    $user = analyticUserAs();
    actingAs($user);

    $scaffold = analyticScaffold();
    $expense = analyticExpenseAccount();
    $offset = ChartOfAccount::factory()->create();

    $entry = analyticPostEntry($scaffold, [
        ['account_id' => $expense->id, 'debit' => 60_000, 'credit' => 0],
        ['account_id' => $offset->id, 'debit' => 0, 'credit' => 60_000],
    ], $user);

    $line = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->firstOrFail();

    [$a, $b] = analyticSectionValues(2);

    app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $a->code, 'shareBp' => 1_000_000],
    ], $user->toAuditActor());

    app(AllocateLineAnalytics::class)->handle($line->id, 'SECTION', [
        ['valueCode' => $a->code, 'shareBp' => 250_000],
        ['valueCode' => $b->code, 'shareBp' => 750_000],
    ], $user->toAuditActor());

    $rows = JournalEntryLineAnalytic::query()
        ->where('journal_entry_line_id', $line->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->sum('amount'))->toBe(60_000);
    expect($rows->sum('share_bp'))->toBe(1_000_000);
});

it('gates allocation on ledger.post and axis configuration on ledger.configure', function () {
    $user = analyticUserAs(withPermission: false);
    actingAs($user);

    expect(fn () => app(AllocateLineAnalytics::class)->handle(1, 'SECTION', [
        ['valueCode' => 'X', 'shareBp' => 1_000_000],
    ], $user->toAuditActor()))->toThrow(AuthorizationException::class);

    expect(fn () => app(ConfigureAnalyticAxis::class)->handle(null, [
        'code' => 'NEWAXIS', 'name' => 'New', 'name_fr' => 'Nouveau',
    ], $user->toAuditActor()))->toThrow(AuthorizationException::class);
});

it('configures an axis under ledger.configure and refuses a code rename', function () {
    $user = analyticUserAs();
    actingAs($user);

    $axis = app(ConfigureAnalyticAxis::class)->handle(null, [
        'code' => 'FUND',
        'name' => 'Funding source',
        'name_fr' => 'Source de financement',
        'is_mandatory' => false,
        'applies_to_classes' => [6],
    ], $user->toAuditActor());

    expect($axis->code)->toBe('FUND');
    expect($axis->applies_to_classes)->toBe([6]);

    $updated = app(ConfigureAnalyticAxis::class)->handle($axis->id, ['is_active' => false], $user->toAuditActor());
    expect($updated->is_active)->toBeFalse();

    expect(fn () => app(ConfigureAnalyticAxis::class)->handle($axis->id, ['code' => 'RENAMED'], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'immutable');
});
