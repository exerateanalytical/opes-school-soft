<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\Checks\LedgerIntegrityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__).'/Accounting/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

it('is ok on a healthy ledger and speaks through the lang files', function () {
    // Unauthenticated on purpose: this check also runs behind the /up
    // endpoint, where nobody is signed in.
    $result = (new LedgerIntegrityCheck)->run();

    expect($result->key)->toBe('ledger.integrity');
    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->label)->toBe(__('opes.health.ledger_integrity.label'));
    expect($result->detail)->toBe(__('opes.health.ledger_integrity.ok_detail'));
    expect($result->remedy)->toBe('');
});

it('goes red with a plain-language remedy when an invariant is violated', function () {
    $calendar = ledgerCalendar();

    $accountId = ChartOfAccount::factory()->create()->id;

    $entry = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2031-03-15',
        'BQ/2031/000001',
        [
            ['account_id' => $accountId, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $accountId, 'debit' => 0, 'credit' => 1000],
        ],
    );

    // A dropped trigger is silent - precisely the corruption path the
    // nightly sweep exists to surface: with the line-lock gone, a member
    // line drifts from the entry's stored totals.
    DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_update');
    DB::table('journal_entry_lines')
        ->where('journal_entry_id', $entry->id)
        ->where('debit', '>', 0)
        ->update(['debit' => 1100]);

    $result = (new LedgerIntegrityCheck)->run();

    expect($result->status)->toBe(HealthStatus::Red);
    expect($result->label)->toBe(__('opes.health.ledger_integrity.label'));
    expect($result->detail)->toContain('L2');
    expect($result->detail)->toBe(__('opes.health.ledger_integrity.red_detail', [
        'count' => 1,
        'invariants' => 'L2',
    ]));
    expect($result->remedy)->toBe(__('opes.health.ledger_integrity.red_remedy'));
    expect($result->remedy)->not->toBe('');
});
