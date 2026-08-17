<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Reconciliation\CloseReconciliationSession;
use App\Modules\Accounting\Actions\Reconciliation\ImportBankStatement;
use App\Modules\Accounting\Actions\Reconciliation\MatchReconciliationLines;
use App\Modules\Accounting\Actions\Reconciliation\OpenReconciliationSession;
use App\Modules\Accounting\Domain\ReconciliationSessionStatus;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\ReconciliationStatement;
use App\Support\Audit\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * docs/specs/02-accounting.md §13.3/§14 - CloseReconciliationSession's own
 * docblock names this the one thing §13.3 asks for that it did not yet do:
 * register the closed état de rapprochement as a hashed, immutable document,
 * the same way GenerateStatutoryBook registers a statutory book.
 */

/**
 * A treasury account ('52' Banks - seeded postable and reconcilable),
 * one posted 100 000 FCFA deposit into it, a bank statement that mirrors it
 * exactly, and the two matched so the état ties to zero - the only state
 * CloseReconciliationSession accepts.
 *
 * @return array{session_id: int, actor: Actor}
 */
function closableReconciliation(): array
{
    Storage::fake('local');

    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = ledgerUser();
    Auth::setUser($user);
    $actor = new Actor((int) $user->getKey(), $user->name);

    $calendar = ledgerCalendar('2033-06-15');

    /** @var ChartOfAccount $treasury */
    $treasury = ChartOfAccount::query()->where('code', '52')->firstOrFail();
    $other = ChartOfAccount::factory()->create();

    $entry = postDirectEntry(
        $calendar['fiscal_year_id'],
        $calendar['accounting_period_id'],
        $calendar['academic_year_id'],
        '2033-06-10',
        'OD/2033/000001',
        [
            ['account_id' => (int) $treasury->getKey(), 'debit' => 100000, 'credit' => 0],
            ['account_id' => (int) $other->getKey(), 'debit' => 0, 'credit' => 100000],
        ],
    );

    $statement = app(ImportBankStatement::class)->handle(
        treasuryAccountId: (int) $treasury->getKey(),
        statementReference: 'BANK-2033-06',
        periodStart: '2033-06-01',
        periodEnd: '2033-06-30',
        openingBalance: 0,
        closingBalance: 100000,
        lines: [[
            'operation_date' => '2033-06-10',
            'value_date' => '2033-06-10',
            'label' => 'Deposit',
            'reference' => 'DEP-1',
            'debit' => 0,
            'credit' => 100000,
        ]],
        actor: $actor,
    );

    $session = app(OpenReconciliationSession::class)->handle(
        treasuryAccountId: (int) $treasury->getKey(),
        accountingPeriodId: (int) $calendar['accounting_period_id'],
        actor: $actor,
        bankStatementId: (int) $statement->getKey(),
    );

    $statementLine = BankStatementLine::query()
        ->where('bank_statement_id', $statement->getKey())
        ->firstOrFail();

    $ledgerLine = JournalEntryLine::query()
        ->where('journal_entry_id', $entry->getKey())
        ->where('account_id', $treasury->getKey())
        ->firstOrFail();

    app(MatchReconciliationLines::class)->handle(
        sessionId: (int) $session->getKey(),
        statementLineIds: [(int) $statementLine->getKey()],
        ledgerLineIds: [(int) $ledgerLine->getKey()],
        actor: $actor,
    );

    return ['session_id' => (int) $session->getKey(), 'actor' => $actor];
}

it('registers the closing statement as a hashed document when the session closes', function (): void {
    $setup = closableReconciliation();

    $session = app(CloseReconciliationSession::class)->handle($setup['session_id'], $setup['actor']);

    expect($session->status)->toBe(ReconciliationSessionStatus::Completed)
        ->and($session->computed_difference)->toBe(0)
        ->and($session->unrecorded_statement_items)->toBe(0);

    /** @var ReconciliationStatement|null $document */
    $document = ReconciliationStatement::query()
        ->where('reconciliation_session_id', $session->getKey())
        ->first();

    expect($document)->not->toBeNull()
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->file_path)->not->toBe('')
        ->and($document->supersedes_id)->toBeNull();

    expect(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    $binary = Storage::disk('local')->get($document->file_path);

    expect($binary)->not->toBeNull()
        ->and(substr((string) $binary, 0, 4))->toBe('%PDF')
        ->and(hash('sha256', (string) $binary))->toBe($document->sha256);
});
