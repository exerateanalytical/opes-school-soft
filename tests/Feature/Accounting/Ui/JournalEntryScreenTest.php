<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Livewire\JournalEntries\Form;
use App\Modules\Accounting\Livewire\JournalEntries\Index;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

if (! function_exists('ledgerUiUserAs')) {
    function ledgerUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

/**
 * A calendar-year FiscalYear (open), a matching open AccountingPeriod for
 * 2026-03, and an academic year covering the same date - DraftJournalEntry
 * derives all three (L6) from the entry's date, so a test that skips any one
 * of them fails inside the Action, not inside this screen.
 */
if (! function_exists('ledgerFiscalYearFixture')) {
    function ledgerFiscalYearFixture(): FiscalYear
    {
        AcademicYear::query()->where('is_current', true)->first()
            ?? AcademicYear::factory()->current()->create([
                'starts_on' => '2025-09-01',
                'ends_on' => '2026-08-31',
                'code' => '2025-2026',
            ]);

        $fiscalYear = FiscalYear::factory()->open()->create([
            'code' => '2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);

        AccountingPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'period_month' => '2026-03-01',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-31',
            'status' => AccountingPeriodStatus::Open,
        ]);

        return $fiscalYear;
    }
}

it('renders through the real route inside the shell', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    get('/ledger/journal-entries')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without ledger.view', function () {
    actingAs(ledgerUiUserAs(Role::Bursar));

    get('/ledger/journal-entries')->assertForbidden();
});

it('403s the create form for a role with ledger.view but not ledger.post', function () {
    actingAs(ledgerUiUserAs(Role::Principal));

    get('/ledger/journal-entries/create')->assertForbidden();
});

it('forbids reaching the components directly without permission', function () {
    actingAs(ledgerUiUserAs(Role::Bursar));

    Livewire::test(Index::class)->assertForbidden();
});

it('lists a posted entry with its piece number and a draft with the placeholder', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $journal = Journal::factory()->create();
    $posted = JournalEntry::factory()->create([
        'journal_id' => $journal->id,
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'OD/2026/000001',
    ]);
    $draft = JournalEntry::factory()->create([
        'journal_id' => $journal->id,
        'status' => JournalEntry::STATUS_DRAFT,
        'piece_no' => null,
    ]);

    Livewire::test(Index::class)
        ->assertSee('OD/2026/000001')
        ->assertSee($draft->label)
        ->assertSee(__('opes.ledger_screen.je_piece_draft_placeholder'));
});

it('creates and posts a real, balanced journal entry end-to-end through the form', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    ledgerFiscalYearFixture();
    $journal = Journal::factory()->create();
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    $component = Livewire::test(Form::class)
        ->set('journalId', (string) $journal->id)
        ->set('date', '2026-03-16')
        ->set('label', 'Test posting')
        ->set('lines.0.account_id', (string) $debitAccount->id)
        ->set('lines.0.account_label', $debitAccount->code)
        ->set('lines.0.label', 'Debit side')
        ->set('lines.0.debit', '5000')
        ->set('lines.1.account_id', (string) $creditAccount->id)
        ->set('lines.1.account_label', $creditAccount->code)
        ->set('lines.1.label', 'Credit side')
        ->set('lines.1.credit', '5000')
        ->call('saveDraft')
        ->assertSet('errorMessage', '');

    $draftId = $component->get('draftEntryId');
    expect($draftId)->not->toBeNull();

    $draftEntry = JournalEntry::query()->findOrFail((int) $draftId);
    expect($draftEntry->status)->toBe(JournalEntry::STATUS_DRAFT);
    expect($draftEntry->lines()->count())->toBe(2);

    $component->call('post')->assertSet('errorMessage', '');

    $posted = JournalEntry::query()->findOrFail((int) $draftId);
    expect($posted->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($posted->piece_no)->not->toBeNull();
    expect($posted->total_debit)->toBe(5000);
    expect($posted->total_credit)->toBe(5000);
});

it('rejects an unbalanced entry with a legible message, not a stack trace', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    ledgerFiscalYearFixture();
    $journal = Journal::factory()->create();
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    $component = Livewire::test(Form::class)
        ->set('journalId', (string) $journal->id)
        ->set('date', '2026-03-16')
        ->set('label', 'Unbalanced test')
        ->set('lines.0.account_id', (string) $debitAccount->id)
        ->set('lines.0.account_label', $debitAccount->code)
        ->set('lines.0.label', 'Debit side')
        ->set('lines.0.debit', '5000')
        ->set('lines.1.account_id', (string) $creditAccount->id)
        ->set('lines.1.account_label', $creditAccount->code)
        ->set('lines.1.label', 'Credit side')
        ->set('lines.1.credit', '4000')
        ->call('saveDraft')
        ->assertSet('errorMessage', '');

    // A draft may be temporarily unbalanced (DraftJournalEntry does not
    // check L2) - the imbalance surfaces when Post is attempted, and this
    // component's live Σdebit/Σcredit readout (rendered from the very same
    // $lines) would already have shown it before this call.
    $component->call('post');

    expect($component->get('errorMessage'))->not->toBe('');
    expect($component->get('errorMessage'))->toContain('does not balance');

    $draftId = $component->get('draftEntryId');
    $entry = JournalEntry::query()->findOrFail((int) $draftId);
    expect($entry->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('filters the list by status', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $journal = Journal::factory()->create();
    JournalEntry::factory()->create([
        'journal_id' => $journal->id, 'status' => JournalEntry::STATUS_DRAFT,
        'piece_no' => null, 'label' => 'Draft entry label',
    ]);
    JournalEntry::factory()->create([
        'journal_id' => $journal->id, 'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'OD/2026/000002', 'label' => 'Posted entry label',
    ]);

    Livewire::test(Index::class)
        ->set('status', 'draft')
        ->assertSee('Draft entry label')
        ->assertDontSee('Posted entry label');
});
