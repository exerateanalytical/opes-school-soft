<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\ReopenFiscalYear;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Tax\Actions\GenerateDsf;
use App\Modules\Tax\Actions\RecordDsfFiling;
use App\Modules\Tax\Domain\DeclarationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DeclarationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * 03-tax-procurement §7.5 / §11.10 / §11.11 - the DSF: the unmapped
 * account block (named), the mapper reconciliation, the pre-filing
 * checklist, and the UNCONDITIONAL reopen block once filed.
 */
if (! function_exists('f5DeclDsfFixture')) {
    /**
     * A closed 2031 fiscal year with one balanced entry over two mapped
     * accounts, hard-locked period, confirmed identity (DGE).
     *
     * @return array{calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, debitAccount: int, creditAccount: int}
     */
    function f5DeclDsfFixture(bool $mapAccounts = true, string $centre = 'DGE'): array
    {
        f5DeclConfirmedIdentity(['tax_centre_type' => $centre, 'is_tva_registered' => false]);

        $calendar = f5DeclCalendar('2031-03-15');
        $debitAccount = f5DeclAccount();
        $creditAccount = f5DeclAccount();

        f5DeclPostEntry($calendar, '2031-03-10', 'F5-DSF-1', [
            ['account_id' => $debitAccount->id, 'debit' => 750_000, 'credit' => 0],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => 750_000],
        ]);

        if ($mapAccounts) {
            DB::table('chart_of_accounts')->where('id', $debitAccount->id)->update(['dsf_line_code' => 'TA-01']);
            DB::table('chart_of_accounts')->where('id', $creditAccount->id)->update(['dsf_line_code' => 'TP-07']);
        }

        f5DeclLockPeriod($calendar['accounting_period_id'], 'hard_locked');
        DB::table('fiscal_years')->where('id', $calendar['fiscal_year_id'])->update(['status' => 'closed']);

        return ['calendar' => $calendar, 'debitAccount' => $debitAccount->id, 'creditAccount' => $creditAccount->id];
    }
}

it('blocks generation and NAMES the unmapped accounts', function () {
    // §11.11: a silently dropped account is a wrong DSF that looks
    // complete - the refusal lists every unmapped account by name.
    $fixture = f5DeclDsfFixture(mapAccounts: false);
    $user = f5DeclUser('tax.declare');

    $debitCode = (string) DB::table('chart_of_accounts')->where('id', $fixture['debitAccount'])->value('code');

    expect(fn () => app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user)))
        ->toThrow(DomainException::class, $debitCode);
});

it('refuses generation from a year still open', function () {
    $fixture = f5DeclDsfFixture();
    DB::table('fiscal_years')->where('id', $fixture['calendar']['fiscal_year_id'])->update(['status' => 'open']);
    $user = f5DeclUser('tax.declare');

    app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));
})->throws(DomainException::class, 'clôture');

it('generates the DSF as a mapper over dsf_line_code with the centre-driven due date', function () {
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('tax.declare');

    $declaration = app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));

    expect($declaration->declaration_type)->toBe('dsf_annual')
        ->and($declaration->period_year)->toBe(2031)
        ->and($declaration->period_month)->toBe(0)
        ->and($declaration->status)->toBe(DeclarationStatus::Generated)
        // §7.6 (seeded, verified): DGE files by 15 March of year+1 - from
        // the OBLIGATION DATA, not a hardcoded match.
        ->and($declaration->due_date?->toDateString())->toBe('2032-03-15')
        ->and($declaration->inputs_hash)->not->toBeNull();

    // Σ mapped = Σ trial balance: the two lines carry the signed movement
    // of their accounts, and the balanced books sum to zero.
    $lines = $declaration->lines()->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('line_code', 'TA-01')?->base_amount)->toBe(750_000)
        ->and($lines->firstWhere('line_code', 'TP-07')?->base_amount)->toBe(-750_000)
        ->and((int) $lines->sum('base_amount'))->toBe(0);
});

it('runs the pre-filing checklist before recording the filing', function () {
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');
    $declaration = app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));

    // Acknowledgement mandatory.
    expect(fn () => app(RecordDsfFiling::class)->handle($declaration->id, '  ', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'external_reference');

    // Checklist: a period slipping back below hard lock blocks filing.
    f5DeclLockPeriod($fixture['calendar']['accounting_period_id'], 'soft_locked');
    expect(fn () => app(RecordDsfFiling::class)->handle($declaration->id, 'IMPOTS-2032-DSF-01', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'not hard-locked');

    // Checklist: the year itself must be CLOSED.
    f5DeclLockPeriod($fixture['calendar']['accounting_period_id'], 'hard_locked');
    DB::table('fiscal_years')->where('id', $fixture['calendar']['fiscal_year_id'])->update(['status' => 'closing']);
    expect(fn () => app(RecordDsfFiling::class)->handle($declaration->id, 'IMPOTS-2032-DSF-01', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'not CLOSED');
});

it('records the filing and stamps the fiscal year', function () {
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');
    $declaration = app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));

    $filed = app(RecordDsfFiling::class)->handle($declaration->id, 'IMPOTS-2032-DSF-01', f5DeclActor($user));

    expect($filed->status)->toBe(DeclarationStatus::Filed);

    $year = DB::table('fiscal_years')->where('id', $fixture['calendar']['fiscal_year_id'])->first();
    expect($year?->dsf_filed_at)->not->toBeNull()
        ->and($year?->dsf_reference)->toBe('IMPOTS-2032-DSF-01')
        ->and((int) $year?->dsf_declaration_id)->toBe($declaration->id)
        ->and($year?->dsf_filed_by)->not->toBeNull();
});

it('blocks ReopenFiscalYear unconditionally once the DSF is filed', function () {
    // §11.10: no flag, no permission override - stated as an absolute
    // because the first support ticket will ask for it.
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('tax.declare', 'tax.file', 'ledger.configure');
    $declaration = app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));
    app(RecordDsfFiling::class)->handle($declaration->id, 'IMPOTS-2032-DSF-01', f5DeclActor($user));

    expect(fn () => app(ReopenFiscalYear::class)->handle($fixture['calendar']['fiscal_year_id'], 'Auditor found an error', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'unconditional');

    // And there IS no force flag to ask for: the Action's signature takes
    // exactly (fiscalYearId, reason, actor) - nothing else to pass.
    $parameters = (new ReflectionMethod(ReopenFiscalYear::class, 'handle'))->getParameters();
    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), $parameters))
        ->toBe(['fiscalYearId', 'reason', 'actor']);
});

it('still reopens a closed year whose DSF is NOT filed', function () {
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('ledger.configure');

    $year = app(ReopenFiscalYear::class)->handle($fixture['calendar']['fiscal_year_id'], 'Close-out error found', f5DeclActor($user));

    expect($year->status)->toBe(FiscalYearStatus::Closing);

    // The hard-locked period dropped back to soft lock for the year-end
    // Actions to correct and re-close.
    $status = (string) DB::table('accounting_periods')->where('id', $fixture['calendar']['accounting_period_id'])->value('status');
    expect($status)->toBe('soft_locked');
});

it('refuses a second DSF for the same year', function () {
    $fixture = f5DeclDsfFixture();
    $user = f5DeclUser('tax.declare');
    app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user));

    expect(fn () => app(GenerateDsf::class)->handle($fixture['calendar']['fiscal_year_id'], f5DeclActor($user)))
        ->toThrow(DomainException::class, 'already exists');
});
