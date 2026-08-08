<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\TrialBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

it('balances debit against credit across two accounts', function () {
    $calendar = ledgerCalendar();
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2031-03-15',
        'OD/2031/000001',
        [
            ['account_id' => $debitAccount->id, 'debit' => 15000, 'credit' => 0],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => 15000],
        ],
    );

    actingAs(ledgerUser());

    $rows = (new TrialBalance())->handle($calendar['fiscal_year_id']);

    $grandDebit = $rows->sum('total_debit');
    $grandCredit = $rows->sum('total_credit');

    expect($grandDebit)->toBe($grandCredit);
    expect($grandDebit)->toBe(15000);

    $byAccount = $rows->keyBy('account_id');
    $debitRow = assertNotNull($byAccount->get($debitAccount->id));
    $creditRow = assertNotNull($byAccount->get($creditAccount->id));
    expect((int) $debitRow->total_debit)->toBe(15000);
    expect((int) $creditRow->total_credit)->toBe(15000);
});

it('includes a reversed entry and its reversal so they net to zero', function () {
    $calendar = ledgerCalendar('2032-06-10');
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    $original = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2032-06-10',
        'OD/2032/000001',
        [
            ['account_id' => $debitAccount->id, 'debit' => 5000, 'credit' => 0],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => 5000],
        ],
    );

    $reversal = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2032-06-10',
        'OD/2032/000002',
        [
            ['account_id' => $debitAccount->id, 'debit' => 0, 'credit' => 5000],
            ['account_id' => $creditAccount->id, 'debit' => 5000, 'credit' => 0],
        ],
    );

    DB::table('journal_entries')->where('id', $original->id)->update([
        'status' => JournalEntry::STATUS_REVERSED,
        'reversed_by_entry_id' => $reversal->id,
    ]);
    DB::table('journal_entries')->where('id', $reversal->id)->update([
        'reverses_entry_id' => $original->id,
        'reversal_reason' => 'Test reversal exceeding ten characters',
    ]);

    actingAs(ledgerUser());

    $rows = (new TrialBalance())->handle($calendar['fiscal_year_id']);
    $byAccount = $rows->keyBy('account_id');

    // Both movements appear (posted convention: reversed status is INCLUDED,
    // not filtered out) and net to zero per account.
    $debitRow = assertNotNull($byAccount->get($debitAccount->id));
    $creditRow = assertNotNull($byAccount->get($creditAccount->id));
    expect((int) $debitRow->total_debit)->toBe(5000);
    expect((int) $debitRow->total_credit)->toBe(5000);
    expect((int) $creditRow->total_debit)->toBe(5000);
    expect((int) $creditRow->total_credit)->toBe(5000);

    expect($rows->sum('total_debit'))->toBe($rows->sum('total_credit'));
});

it('excludes draft entries from the trial balance', function () {
    $calendar = ledgerCalendar('2033-01-20');
    $journal = Journal::factory()->create();
    $account = ChartOfAccount::factory()->create();

    $draft = JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'date' => '2033-01-20',
        'value_date' => '2033-01-20',
        'accounting_period_id' => $calendar['accounting_period_id'],
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
        'label' => 'Untouched draft',
        'status' => JournalEntry::STATUS_DRAFT,
    ]);

    JournalEntryLine::query()->create([
        'journal_entry_id' => $draft->id,
        'sequence' => 1,
        'account_id' => $account->id,
        'label' => 'Draft line',
        'debit' => 9999,
        'credit' => 0,
    ]);

    actingAs(ledgerUser());

    $rows = (new TrialBalance())->handle($calendar['fiscal_year_id']);

    expect($rows->keyBy('account_id')->has($account->id))->toBeFalse();
});

it('denies a user without ledger.view', function () {
    actingAs(ledgerUser(Role::Bursar));

    (new TrialBalance())->handle(1);
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);
