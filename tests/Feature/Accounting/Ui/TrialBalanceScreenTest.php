<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\AddJournalEntryLine;
use App\Modules\Accounting\Actions\DraftJournalEntry;
use App\Modules\Accounting\Actions\PostJournalEntry;
use App\Modules\Accounting\Livewire\Reports\TrialBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
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
 * A real posted, balanced journal entry, built through the same Actions the
 * form screen calls - so the trial balance under test reflects a genuine
 * posting, not a row inserted straight into the table.
 */
function postARealBalancedEntry(int $journalId, int $debitAccountId, int $creditAccountId, int $amount): \App\Modules\Accounting\Models\JournalEntry
{
    // A null user here would mean the calling test forgot actingAs() first -
    // a real setup bug worth surfacing loudly, not silently falling back to
    // a system actor the way production code does when there genuinely is
    // no session.
    $actor = assertNotNull(auth()->user())->toAuditActor();

    $entry = app(DraftJournalEntry::class)->handle(
        journalId: $journalId,
        date: '2026-03-16',
        valueDate: null,
        label: 'Trial balance fixture',
        reference: null,
        lines: [
            ['account_id' => $debitAccountId, 'label' => 'Debit', 'debit' => $amount, 'credit' => 0],
            ['account_id' => $creditAccountId, 'label' => 'Credit', 'debit' => 0, 'credit' => $amount],
        ],
        actor: $actor,
    );

    return app(PostJournalEntry::class)->handle((int) $entry->getKey(), $actor);
}

it('renders through the real route inside the shell', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    get('/ledger/trial-balance')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without ledger.view', function () {
    actingAs(ledgerUiUserAs(Role::Bursar));

    get('/ledger/trial-balance')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(ledgerUiUserAs(Role::Bursar));

    Livewire::test(TrialBalance::class)->assertForbidden();
});

it('shows a real posted entry and the grand total balances', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $academicYear = \App\Modules\Academics\Models\AcademicYear::factory()->current()->create([
        'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'code' => '2025-2026',
    ]);
    $fiscalYear = \App\Modules\Accounting\Models\FiscalYear::factory()->open()->create([
        'code' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ]);
    \App\Modules\Accounting\Models\AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_month' => '2026-03-01', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31',
        'status' => \App\Modules\Accounting\Domain\AccountingPeriodStatus::Open,
    ]);

    $journal = Journal::factory()->create();
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    postARealBalancedEntry($journal->id, $debitAccount->id, $creditAccount->id, 12000);

    Livewire::test(TrialBalance::class)
        ->set('fiscalYearId', (string) $fiscalYear->id)
        ->assertSee($debitAccount->code)
        ->assertSee($creditAccount->code)
        ->assertViewHas('totalDebit', 12000)
        ->assertViewHas('totalCredit', 12000);
});

it('is empty rather than fabricated when the fiscal year has no posted movement', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $fiscalYear = \App\Modules\Accounting\Models\FiscalYear::factory()->create([
        'code' => '2099',
    ]);

    Livewire::test(TrialBalance::class)
        ->set('fiscalYearId', (string) $fiscalYear->id)
        ->assertSee(__('opes.ledger_screen.tb_empty'));
});
