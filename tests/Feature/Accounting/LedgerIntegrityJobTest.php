<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\LetterEntries;
use App\Modules\Accounting\Actions\VerifyLedgerIntegrity;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Local helpers. Deliberately self-contained (LetteringTest's helpers are only
// loaded when Pest happens to load that file first), each guarded so a full
// suite run does not redeclare them.
// ---------------------------------------------------------------------------

if (! function_exists('integrityUserAs')) {
    function integrityUserAs(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.post', 'web');
        Permission::findOrCreate('ledger.view', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('ledger.post', 'ledger.view');

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('integrityArtisan')) {
    /**
     * artisan() is typed PendingCommand|int; in a Feature test it is always
     * the former, and PHPStan rightly refuses assertions on the union.
     */
    function integrityArtisan(string $command): PendingCommand
    {
        $pending = artisan($command);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException('artisan() ran the command immediately instead of returning a PendingCommand.');
        }

        return $pending;
    }
}

if (! function_exists('integrityAcademicYearId')) {
    function integrityAcademicYearId(Carbon $anchor): int
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

if (! function_exists('integrityLedger')) {
    /**
     * @return array{
     *     user: User,
     *     fiscal_year: FiscalYear,
     *     period: AccountingPeriod,
     *     academic_year_id: int,
     *     journal: Journal,
     * }
     */
    function integrityLedger(): array
    {
        $user = integrityUserAs();
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
            'status' => 'open',
            'is_quarter_end' => false,
        ]);

        return [
            'user' => $user,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'academic_year_id' => integrityAcademicYearId($today),
            'journal' => Journal::factory()->create(),
        ];
    }
}

if (! function_exists('integrityPostEntry')) {
    /**
     * Same draft-lines-then-post dance as the other Accounting test helpers
     * (the L3 trigger rightly refuses lines on a posted entry), with an
     * EXPLICIT sequence number so the L7 test can plant a gap on purpose.
     *
     * @param  array{fiscal_year: FiscalYear, period: AccountingPeriod, academic_year_id: int, journal: Journal, user: User}  $ledger
     * @param  list<array<string, mixed>>  $lines
     */
    function integrityPostEntry(array $ledger, int $sequence, array $lines): JournalEntry
    {
        $pieceNo = sprintf(
            '%s/%s/%06d',
            $ledger['journal']->code,
            $ledger['fiscal_year']->code,
            $sequence,
        );

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
            'label' => 'Integrity test entry',
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
            'piece_no' => $pieceNo,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'posted_by' => $ledger['user']->id,
            'posted_at' => now(),
        ])->save();

        return $entry->fresh() ?? $entry;
    }
}

if (! function_exists('integrityDropLineTriggers')) {
    /**
     * The whole point of the nightly job is to catch what the guards missed:
     * a trigger dropped in production is silent. These tests reproduce that
     * exact failure mode, then plant the corruption the trigger would have
     * refused.
     *
     * The drop MUST be undone by integrityRestoreLineTriggers() before the
     * test ends. RefreshDatabase does NOT restore them mid-suite: it migrates
     * once per process and then wraps each test in a transaction, and DROP
     * TRIGGER is DDL - it implicitly commits and survives the rollback. An
     * unrestored drop would leave every later test in the same run without
     * L3/L8 protection, including LedgerInvariantsTest's direct-SQL proofs of
     * those very triggers - the same committed-leak class that once broke 28
     * unrelated tests in this suite.
     */
    function integrityDropLineTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_l8_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_l8_before_update');
    }
}

if (! function_exists('integrityRestoreLineTriggers')) {
    /**
     * Re-creates the dropped triggers from the MIGRATIONS' OWN SOURCE, so a
     * future edit to a trigger body cannot silently diverge from what this
     * restore installs. Extraction, not duplication.
     */
    function integrityRestoreLineTriggers(): void
    {
        $migrationFiles = [
            database_path('migrations/2026_08_07_230008_create_journal_entry_lines_table.php'),
            database_path('migrations/2026_08_07_230010_add_auxiliary_columns_to_journal_entry_lines.php'),
        ];

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                throw new RuntimeException("Cannot read {$file} to restore its triggers.");
            }

            // Anchored to a standalone END line: trigger bodies contain
            // "END IF;" lines, and a bare non-greedy END\b would stop at the
            // first of those, installing a truncated (and invalid) trigger.
            preg_match_all('/CREATE TRIGGER.*?\n[ \t]*END[ \t]*\r?$/ms', $source, $matches);

            if ($matches[0] === []) {
                throw new RuntimeException("No CREATE TRIGGER blocks found in {$file} - the restore would silently install nothing.");
            }

            foreach ($matches[0] as $ddl) {
                // Drop by the extracted trigger's OWN name first: the
                // migrations define five triggers but the tests drop only
                // four, and re-creating a still-installed one (the delete
                // lock) raises "trigger already exists".
                if (preg_match('/CREATE TRIGGER (\S+)/', $ddl, $name) !== 1) {
                    throw new RuntimeException('Extracted a CREATE TRIGGER block with no parseable name.');
                }

                // PDO::exec, not DB::unprepared: unprepared()'s signature
                // demands a literal-string precisely so runtime-assembled SQL
                // cannot slip through it - the right default. This DDL is
                // extracted from our own migration source and the name from
                // that same block, so replaying it at the PDO level is the
                // honest way to say "this is trusted DDL replay", not a
                // loophole around the injection guard.
                DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS '.$name[1]);
                DB::connection()->getPdo()->exec($ddl);
            }
        }
    }
}

afterEach(function (): void {
    integrityRestoreLineTriggers();
});

if (! function_exists('integrityBalancedPair')) {
    /**
     * @return array{0: ChartOfAccount, 1: ChartOfAccount} [collective, bank]
     */
    function integrityBalancedPair(): array
    {
        $collective = ChartOfAccount::factory()->create([
            'is_collective' => true,
            'requires_partner' => true,
            'allowed_partner_types' => ['student'],
            'is_lettrable' => true,
        ]);

        return [$collective, ChartOfAccount::factory()->create()];
    }
}

// ---------------------------------------------------------------------------
// The Action
// ---------------------------------------------------------------------------

it('reports every invariant clean on a healthy ledger', function () {
    $ledger = integrityLedger();
    [$collective, $bank] = integrityBalancedPair();

    integrityPostEntry($ledger, 1, [
        ['account_id' => $collective->id, 'debit' => 50000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 7],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50000],
    ]);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect(array_keys($report))->toBe(VerifyLedgerIntegrity::INVARIANTS);

    foreach ($report as $findings) {
        expect($findings)->toBe([]);
    }
});

it('L2: reports an entry whose stored totals no longer match its lines', function () {
    $ledger = integrityLedger();
    [, $bank] = integrityBalancedPair();
    $other = ChartOfAccount::factory()->create();

    $entry = integrityPostEntry($ledger, 1, [
        ['account_id' => $other->id, 'debit' => 30000, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 30000],
    ]);

    // The totals themselves are guarded by CHECK ck_je_totals, so the
    // corruption goes in through a member line once the L3 line-lock
    // trigger is gone - a dropped trigger being exactly the silent failure
    // this job exists to catch. Lines now sum to 30100 against stored
    // totals of 30000.
    integrityDropLineTriggers();
    DB::table('journal_entry_lines')
        ->where('journal_entry_id', $entry->id)
        ->where('debit', '>', 0)
        ->update(['debit' => 30100]);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L2'])->toHaveCount(1);
    expect($report['L2'][0]['entry_id'])->toBe($entry->id);
    expect($report['L2'][0]['total_debit'])->toBe(30000);
    expect($report['L2'][0]['line_debit'])->toBe(30100);
});

it('L5: reports a posted entry sitting in a period hard-locked before it was posted', function () {
    $ledger = integrityLedger();
    [, $bank] = integrityBalancedPair();
    $other = ChartOfAccount::factory()->create();

    $entry = integrityPostEntry($ledger, 1, [
        ['account_id' => $other->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 1000],
    ]);

    // Rewrite history: the period claims it was hard-locked a day before the
    // entry was posted into it. No trigger watches accounting_periods.
    DB::table('accounting_periods')->where('id', $ledger['period']->id)->update([
        'status' => 'hard_locked',
        'hard_locked_at' => now()->subDay(),
        'hard_locked_by' => $ledger['user']->id,
    ]);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L5'])->toHaveCount(1);
    expect($report['L5'][0]['entry_id'])->toBe($entry->id);
    expect($report['L5'][0]['period_id'])->toBe($ledger['period']->id);
});

it('L7: reports a sequence gap per (journal, fiscal_year)', function () {
    $ledger = integrityLedger();
    [, $bank] = integrityBalancedPair();
    $other = ChartOfAccount::factory()->create();

    $lines = [
        ['account_id' => $other->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 1000],
    ];

    // Pieces 000001 and 000003: two entries, MAX(seq) = 3. Piece 000002
    // never existed - the gap a locked SequenceAllocator is supposed to
    // make impossible.
    integrityPostEntry($ledger, 1, $lines);
    integrityPostEntry($ledger, 3, $lines);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L7'])->toHaveCount(1);
    expect($report['L7'][0]['journal_id'])->toBe($ledger['journal']->id);
    expect($report['L7'][0]['fiscal_year_id'])->toBe($ledger['fiscal_year']->id);
    expect($report['L7'][0]['entry_count'])->toBe(2);
    expect($report['L7'][0]['max_sequence'])->toBe(3);
});

it('L8: reports both directions once the trigger that made it impossible is gone', function () {
    $ledger = integrityLedger();
    [$collective, $bank] = integrityBalancedPair();

    integrityDropLineTriggers();

    $entry = integrityPostEntry($ledger, 1, [
        // Collective account, no partner - the trigger would have refused it.
        ['account_id' => $collective->id, 'debit' => 20000, 'credit' => 0],
        // Non-collective account carrying a partner - refused the other way.
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 20000, 'partner_type' => 'student', 'partner_id' => 4],
    ]);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L8'])->toHaveCount(2);

    $problems = array_column($report['L8'], 'problem', 'account_id');
    expect($problems[$collective->id])->toBe('missing_partner');
    expect($problems[$bank->id])->toBe('stray_partner');
    expect($report['L8'][0]['entry_id'])->toBe($entry->id);
});

it('L10: downgrades a full lettering group that no longer nets to zero and reports it', function () {
    $ledger = integrityLedger();
    [$collective, $bank] = integrityBalancedPair();

    $invoice = integrityPostEntry($ledger, 1, [
        ['account_id' => $collective->id, 'debit' => 50000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 7],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50000],
    ]);
    $payment = integrityPostEntry($ledger, 2, [
        ['account_id' => $bank->id, 'debit' => 50000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 50000, 'partner_type' => 'student', 'partner_id' => 7],
    ]);

    $invoiceLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->id)->where('account_id', $collective->id)->firstOrFail();
    $paymentLine = JournalEntryLine::query()->where('journal_entry_id', $payment->id)->where('account_id', $collective->id)->firstOrFail();

    $group = app(LetterEntries::class)->handle([$invoiceLine->id, $paymentLine->id], $ledger['user']->toAuditActor());
    expect($group->status)->toBe(LetteringStatus::Full);

    // Corrupt one member past the line-lock trigger: the group's lines no
    // longer net to zero, but the Lettering row still says `full`.
    integrityDropLineTriggers();
    DB::table('journal_entry_lines')->where('id', $paymentLine->id)->update(['credit' => 40000]);

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L10'])->toHaveCount(1);
    expect($report['L10'][0]['lettering_id'])->toBe($group->id);
    expect($report['L10'][0]['action'])->toBe('downgraded_to_partial');

    // The downgrade actually happened, and it was audited.
    expect($group->refresh()->status)->toBe(LetteringStatus::Partial);
    expect(DB::table('audit_logs')
        ->where('auditable_type', 'like', '%Lettering')
        ->where('auditable_id', $group->id)
        ->where('action', 'updated')
        ->exists())->toBeTrue();
});

it('L11: reports finding-free while the analytic tables are not yet installed', function () {
    integrityLedger();

    $report = app(VerifyLedgerIntegrity::class)->handle();

    expect($report['L11'])->toBe([]);
})->skip(
    fn (): bool => Schema::hasTable('journal_entry_line_analytics'),
    'Analytic tables exist; the not-yet-installed path no longer applies.',
);

// ---------------------------------------------------------------------------
// The command
// ---------------------------------------------------------------------------

it('opes:ledger:verify exits 0 on a clean ledger', function () {
    $ledger = integrityLedger();
    [, $bank] = integrityBalancedPair();
    $other = ChartOfAccount::factory()->create();

    integrityPostEntry($ledger, 1, [
        ['account_id' => $other->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 1000],
    ]);

    // The nightly run is unattended: no authenticated user may be required.
    auth()->logout();

    integrityArtisan('opes:ledger:verify')->assertExitCode(0);
});

it('opes:ledger:verify exits 1 and names the invariant when a finding exists', function () {
    $ledger = integrityLedger();
    [, $bank] = integrityBalancedPair();
    $other = ChartOfAccount::factory()->create();

    $entry = integrityPostEntry($ledger, 1, [
        ['account_id' => $other->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 1000],
    ]);

    integrityDropLineTriggers();
    DB::table('journal_entry_lines')
        ->where('journal_entry_id', $entry->id)
        ->where('credit', '>', 0)
        ->update(['credit' => 900]);

    auth()->logout();

    integrityArtisan('opes:ledger:verify')
        ->expectsOutputToContain('L2: 1 finding(s)')
        ->assertExitCode(1);
});
