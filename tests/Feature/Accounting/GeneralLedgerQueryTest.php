<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\GeneralLedgerQuery;
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

it('lists an account\'s posted lines chronologically with a running balance', function () {
    $calendar = ledgerCalendar('2034-02-01');
    $account = ChartOfAccount::factory()->create();
    $other = ChartOfAccount::factory()->create();

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2034-02-05',
        'OD/2034/000002',
        [
            ['account_id' => $account->id, 'debit' => 3000, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => 3000],
        ],
    );

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2034-02-01',
        'OD/2034/000001',
        [
            ['account_id' => $account->id, 'debit' => 0, 'credit' => 1000],
            ['account_id' => $other->id, 'debit' => 1000, 'credit' => 0],
        ],
    );

    actingAs(ledgerUser());

    $lines = (new GeneralLedgerQuery())->handle($account->id, $calendar['fiscal_year_id']);

    expect($lines)->toHaveCount(2);
    $firstLine = assertNotNull($lines->first());
    $lastLine = assertNotNull($lines->last());
    // Chronological: the 02-01 credit line comes before the 02-05 debit line.
    expect($firstLine->date)->toBe('2034-02-01');
    expect($firstLine->credit)->toBe(1000);
    expect($firstLine->running_balance)->toBe(-1000);
    expect($lastLine->debit)->toBe(3000);
    expect($lastLine->running_balance)->toBe(2000);
});

it('excludes lines belonging to other accounts', function () {
    $calendar = ledgerCalendar('2035-05-05');
    $account = ChartOfAccount::factory()->create();
    $other = ChartOfAccount::factory()->create();

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2035-05-05',
        'OD/2035/000001',
        [
            ['account_id' => $account->id, 'debit' => 2000, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => 2000],
        ],
    );

    actingAs(ledgerUser());

    $lines = (new GeneralLedgerQuery())->handle($other->id, $calendar['fiscal_year_id']);

    expect($lines)->toHaveCount(1);
    $firstLine = assertNotNull($lines->first());
    expect($firstLine->credit)->toBe(2000);
});

it('includes both halves of a reversed pair, netting to zero', function () {
    $calendar = ledgerCalendar('2036-07-07');
    $account = ChartOfAccount::factory()->create();
    $other = ChartOfAccount::factory()->create();

    $original = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2036-07-07',
        'OD/2036/000001',
        [
            ['account_id' => $account->id, 'debit' => 4000, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => 4000],
        ],
    );

    $reversal = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2036-07-07',
        'OD/2036/000002',
        [
            ['account_id' => $account->id, 'debit' => 0, 'credit' => 4000],
            ['account_id' => $other->id, 'debit' => 4000, 'credit' => 0],
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

    $lines = (new GeneralLedgerQuery())->handle($account->id, $calendar['fiscal_year_id']);

    expect($lines)->toHaveCount(2);
    $lastLine = assertNotNull($lines->last());
    expect($lastLine->running_balance)->toBe(0);
});

it('excludes draft entries', function () {
    $calendar = ledgerCalendar('2037-09-09');
    $journal = Journal::factory()->create();
    $account = ChartOfAccount::factory()->create();

    $draft = JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'date' => '2037-09-09',
        'value_date' => '2037-09-09',
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
        'debit' => 7777,
        'credit' => 0,
    ]);

    actingAs(ledgerUser());

    $lines = (new GeneralLedgerQuery())->handle($account->id, $calendar['fiscal_year_id']);

    expect($lines)->toHaveCount(0);
});

it('filters by date range', function () {
    $calendar = ledgerCalendar('2038-03-03');
    $account = ChartOfAccount::factory()->create();
    $other = ChartOfAccount::factory()->create();

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2038-03-03',
        'OD/2038/000001',
        [
            ['account_id' => $account->id, 'debit' => 1500, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => 1500],
        ],
    );

    postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2038-03-20',
        'OD/2038/000002',
        [
            ['account_id' => $account->id, 'debit' => 2500, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => 2500],
        ],
    );

    actingAs(ledgerUser());

    $lines = (new GeneralLedgerQuery())->handle(
        $account->id,
        $calendar['fiscal_year_id'],
        ['from' => '2038-03-10', 'to' => '2038-03-31'],
    );

    expect($lines)->toHaveCount(1);
    $firstLine = assertNotNull($lines->first());
    expect($firstLine->debit)->toBe(2500);
});

it('denies a user without ledger.view', function () {
    actingAs(ledgerUser(Role::Bursar));

    (new GeneralLedgerQuery())->handle(1, 1);
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);
