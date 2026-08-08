<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\ImportOpeningAuxiliaryBalances;
use App\Modules\Accounting\Actions\ImportOpeningTrialBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

if (! function_exists('openingUserAs')) {
    function openingUserAs(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        SpatiePermission::findOrCreate(Permission::LedgerPost->value, 'web');

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::LedgerPost->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('openingCalendar')) {
    /**
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function openingCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('openingSuspense')) {
    /**
     * Builds `471` -> `4712` under the seeded `47` parent, respecting the
     * CoA hierarchy triggers (one digit per level, parent non-postable).
     */
    function openingSuspense(): ChartOfAccount
    {
        /** @var ChartOfAccount $parent47 */
        $parent47 = ChartOfAccount::query()->where('code', '47')->firstOrFail();

        /** @var ChartOfAccount $level471 */
        $level471 = ChartOfAccount::query()->create([
            'code' => '471',
            'parent_id' => $parent47->getKey(),
            'name' => 'Suspense accounts',
            'name_fr' => 'Comptes d\'attente',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => false,
        ]);

        /** @var ChartOfAccount $suspense */
        $suspense = ChartOfAccount::query()->create([
            'code' => '4712',
            'parent_id' => $level471->getKey(),
            'name' => 'Migration suspense',
            'name_fr' => 'Compte d\'attente - migration',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
        ]);

        return $suspense;
    }
}

if (! function_exists('openingArtisan')) {
    /**
     * @param  array<string, mixed>  $parameters
     */
    function openingArtisan(array $parameters): PendingCommand
    {
        $pending = artisan('opes:ledger:import-opening', $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException('artisan() ran the command immediately instead of returning a PendingCommand.');
        }

        return $pending;
    }
}

if (! function_exists('openingAccountId')) {
    function openingAccountId(string $code): int
    {
        return (int) ChartOfAccount::query()->where('code', $code)->firstOrFail()->getKey();
    }
}

it('posts a balanced opening trial balance as one migration entry in the AN journal', function () {
    $user = openingUserAs();
    actingAs($user);
    $calendar = openingCalendar();

    $entry = app(ImportOpeningTrialBalance::class)->handle(
        fiscalYearId: $calendar['fiscal_year_id'],
        rows: [
            ['account_code' => '52', 'debit' => 500_000, 'credit' => 0],
            ['account_code' => '57', 'debit' => 100_000, 'credit' => 0],
            ['account_code' => '11', 'debit' => 0, 'credit' => 600_000],
        ],
        asOfDate: '2031-03-15',
        actor: $user->toAuditActor(),
    );

    /** @var Journal $an */
    $an = Journal::query()->where('code', 'AN')->firstOrFail();

    expect($entry->journal_id)->toBe((int) $an->getKey());
    expect($entry->is_migration)->toBeTrue();
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($entry->piece_no)->not->toBeNull();
    expect($entry->total_debit)->toBe(600_000);
    expect($entry->total_credit)->toBe(600_000);
    expect($entry->lines)->toHaveCount(3);
    expect(JournalEntry::query()->count())->toBe(1);
});

it('refuses an unbalanced trial balance naming the difference, posting nothing', function () {
    $user = openingUserAs();
    actingAs($user);
    $calendar = openingCalendar();

    try {
        app(ImportOpeningTrialBalance::class)->handle(
            fiscalYearId: $calendar['fiscal_year_id'],
            rows: [
                ['account_code' => '52', 'debit' => 500_000, 'credit' => 0],
                ['account_code' => '11', 'debit' => 0, 'credit' => 350_000],
            ],
            asOfDate: '2031-03-15',
            actor: $user->toAuditActor(),
        );

        $thrown = false;
    } catch (DomainException $exception) {
        $thrown = true;
        expect($exception->getMessage())->toContain('does not balance');
        expect($exception->getMessage())->toContain('difference');
    }

    expect($thrown)->toBeTrue();
    expect(JournalEntry::query()->count())->toBe(0);
});

it('refuses unknown, archived and non-postable accounts with the row number', function () {
    $user = openingUserAs();
    actingAs($user);
    openingCalendar();

    /** @var ChartOfAccount $archived */
    $archived = ChartOfAccount::factory()->create(['is_archived' => true, 'archived_at' => '2030-01-01']);

    $result = app(ImportOpeningTrialBalance::class)->validate([
        ['account_code' => '99999999', 'debit' => 100, 'credit' => 0],
        ['account_code' => $archived->code, 'debit' => 100, 'credit' => 0],
        ['account_code' => '47', 'debit' => 0, 'credit' => 200],
    ]);

    expect($result['errors'])->toHaveCount(3);
    expect($result['errors'][0])->toContain('Row 1')->toContain('does not exist');
    expect($result['errors'][1])->toContain('Row 2')->toContain('archived');
    expect($result['errors'][2])->toContain('Row 3')->toContain('not postable');
});

it('refuses collective accounts in the trial balance and redirects to the auxiliary import', function () {
    $user = openingUserAs();
    actingAs($user);
    $calendar = openingCalendar();

    try {
        app(ImportOpeningTrialBalance::class)->handle(
            fiscalYearId: $calendar['fiscal_year_id'],
            rows: [
                ['account_code' => '4111', 'debit' => 500_000, 'credit' => 0],
                ['account_code' => '11', 'debit' => 0, 'credit' => 500_000],
            ],
            asOfDate: '2031-03-15',
            actor: $user->toAuditActor(),
        );

        $thrown = false;
    } catch (DomainException $exception) {
        $thrown = true;
        expect($exception->getMessage())->toContain('4111');
        expect($exception->getMessage())->toContain('collective');
        expect($exception->getMessage())->toContain('auxiliary import');
    }

    expect($thrown)->toBeTrue();
    expect(JournalEntry::query()->count())->toBe(0);
});

it('refuses an auxiliary import whose sums do not match what the trial balance posted', function () {
    $user = openingUserAs();
    actingAs($user);
    $calendar = openingCalendar();
    openingSuspense();

    app(ImportOpeningTrialBalance::class)->handle(
        fiscalYearId: $calendar['fiscal_year_id'],
        rows: [
            ['account_code' => '4712', 'debit' => 500_000, 'credit' => 0],
            ['account_code' => '11', 'debit' => 0, 'credit' => 500_000],
        ],
        asOfDate: '2031-03-15',
        actor: $user->toAuditActor(),
    );

    $student = Student::factory()->create();

    try {
        app(ImportOpeningAuxiliaryBalances::class)->handle(
            fiscalYearId: $calendar['fiscal_year_id'],
            rows: [
                ['account_code' => '4111', 'partner_type' => 'student', 'partner_id' => (int) $student->getKey(), 'amount_signed' => 400_000],
            ],
            asOfDate: '2031-03-15',
            actor: $user->toAuditActor(),
        );

        $thrown = false;
    } catch (DomainException $exception) {
        $thrown = true;
        expect($exception->getMessage())->toContain('do not match');
        expect($exception->getMessage())->toContain('difference');
        expect($exception->getMessage())->toContain('4111');
    }

    expect($thrown)->toBeTrue();
    expect(JournalEntry::query()->count())->toBe(1); // only the trial balance
});

it('posts auxiliary balances per partner and clears the suspense to zero', function () {
    $user = openingUserAs();
    actingAs($user);
    $calendar = openingCalendar();
    $suspense = openingSuspense();

    app(ImportOpeningTrialBalance::class)->handle(
        fiscalYearId: $calendar['fiscal_year_id'],
        rows: [
            ['account_code' => '4712', 'debit' => 500_000, 'credit' => 0],
            ['account_code' => '11', 'debit' => 0, 'credit' => 500_000],
        ],
        asOfDate: '2031-03-15',
        actor: $user->toAuditActor(),
    );

    $alpha = Student::factory()->create();
    $beta = Student::factory()->create();

    $entry = app(ImportOpeningAuxiliaryBalances::class)->handle(
        fiscalYearId: $calendar['fiscal_year_id'],
        rows: [
            ['account_code' => '4111', 'partner_type' => 'student', 'partner_id' => (int) $alpha->getKey(), 'amount_signed' => 300_000, 'due_date' => '2031-04-01'],
            ['account_code' => '4111', 'partner_type' => 'student', 'partner_id' => (int) $beta->getKey(), 'amount_signed' => 200_000],
        ],
        asOfDate: '2031-03-15',
        actor: $user->toAuditActor(),
    );

    expect($entry->is_migration)->toBeTrue();
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($entry->lines)->toHaveCount(3); // two partner lines + one suspense counter-line

    $partnerLines = $entry->lines->where('account_id', openingAccountId('4111'))->values();
    expect($partnerLines)->toHaveCount(2);
    expect($partnerLines->pluck('partner_type')->map(fn (mixed $type): string => $type instanceof BackedEnum ? (string) $type->value : (string) $type)->unique()->all())->toBe(['student']);
    expect($partnerLines->pluck('partner_id')->sort()->values()->all())
        ->toBe(collect([(int) $alpha->getKey(), (int) $beta->getKey()])->sort()->values()->all());

    // The suspense is exactly cleared: L9 holds by construction.
    $suspenseBalance = (int) Illuminate\Support\Facades\DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('l.account_id', $suspense->getKey())
        ->where('e.status', JournalEntry::STATUS_POSTED)
        ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as bal')
        ->value('bal');

    expect($suspenseBalance)->toBe(0);
});

it('previews a trial-balance CSV without posting, then posts with --force', function () {
    $user = openingUserAs();
    $calendar = openingCalendar();

    $file = tempnam(sys_get_temp_dir(), 'otb');
    expect($file)->toBeString();
    assert(is_string($file));
    file_put_contents($file, "account_code,debit,credit\n52,500000,0\n11,0,500000\n");

    openingArtisan([
        'file' => $file,
        '--as-of' => '2031-03-15',
        '--user' => $user->email,
    ])->expectsOutputToContain('Preview only - nothing was posted.')->assertExitCode(0);

    expect(JournalEntry::query()->count())->toBe(0);

    openingArtisan([
        'file' => $file,
        '--as-of' => '2031-03-15',
        '--user' => $user->email,
        '--force' => true,
    ])->expectsOutputToContain('into the AN journal')->assertExitCode(0);

    $entry = JournalEntry::query()->firstOrFail();
    expect($entry->is_migration)->toBeTrue();
    expect($entry->fiscal_year_id)->toBe($calendar['fiscal_year_id']);
    expect((int) $entry->created_by)->toBe((int) $user->getKey()); // §20: the maker is recorded

    unlink($file);
});

it('reports every rejected CSV row with its number and reason and exits non-zero', function () {
    $user = openingUserAs();
    openingCalendar();

    $file = tempnam(sys_get_temp_dir(), 'otb');
    expect($file)->toBeString();
    assert(is_string($file));
    file_put_contents($file, "account_code,debit,credit\n99999999,100,0\n4111,200,0\n11,0,300\n");

    openingArtisan([
        'file' => $file,
        '--as-of' => '2031-03-15',
        '--user' => $user->email,
        '--force' => true,
    ])->expectsOutputToContain('The import was refused. Fix every line below and run again:')
        ->expectsOutputToContain('Row 1')
        ->assertExitCode(1);

    expect(JournalEntry::query()->count())->toBe(0);

    unlink($file);
});
