<?php

declare(strict_types=1);

/**
 * Regression cover for the accounting-screen UI pass: controls that rendered
 * but did nothing, handlers that existed with nothing bound to them, and
 * figures that were literals in the view rather than reads of the ledger.
 *
 * Each test names the specific defect it locks down, because "the screen
 * renders" is exactly the assertion that let these ship in the first place.
 */

use App\Modules\Accounting\Livewire\Books\Index as BooksIndex;
use App\Modules\Accounting\Livewire\ChartOfAccounts\Index as ChartOfAccountsIndex;
use App\Modules\Accounting\Livewire\JournalEntries\Index as JournalEntriesIndex;
use App\Modules\Accounting\Livewire\Reconciliation\Index as ReconciliationIndex;
use App\Modules\Accounting\Livewire\Reports\Index as ReportsIndex;
use App\Modules\Accounting\Livewire\SystemDocumentation\Index as SystemDocumentationIndex;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('accountingUiUserAs')) {
    function accountingUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

// ── Reset-filter controls: the handler existed, the button did not ──────────

it('wires the reset control on the journal entries list to resetFilters', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(JournalEntriesIndex::class)
        ->assertSeeHtml('wire:click="resetFilters"')
        ->set('status', 'draft')
        ->set('dateFrom', '2026-01-01')
        ->call('resetFilters')
        ->assertSet('status', '')
        ->assertSet('dateFrom', '');
});

it('wires the reset control on the chart of accounts to resetFilters', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(ChartOfAccountsIndex::class)
        ->assertSeeHtml('wire:click="resetFilters"')
        ->set('search', '411')
        ->set('postableOnly', true)
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('postableOnly', false);
});

it('wires the reset control on the financial reports screen to resetFilters', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(ReportsIndex::class)
        ->assertSeeHtml('wire:click="resetFilters"');
});

// ── Reconciliation: selections must not survive a period change ─────────────

it('clears line selections when the reconciliation period changes', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    // The period picker is a wire:model.live select, so this is the path the
    // screen actually takes - selectPeriod() is never called from the blade.
    Livewire::test(ReconciliationIndex::class)
        ->set('selectedStatementLines', [1, 2])
        ->set('selectedLedgerLines', [3])
        ->set('periodId', 999)
        ->assertSet('selectedStatementLines', [])
        ->assertSet('selectedLedgerLines', []);
});

it('clears line selections when the reconciliation account changes', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(ReconciliationIndex::class)
        ->set('selectedStatementLines', [1])
        ->set('accountId', 999)
        ->assertSet('selectedStatementLines', []);
});

// ── Honest empty state instead of a fabricated zero ─────────────────────────

it('shows no entry count rather than zero when no fiscal year is open', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    // A year exists, but none of them is open. The distinction under test is
    // "no open year" vs "the open year has no entries" - both used to print 0.
    FiscalYear::factory()->create(['code' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

    Livewire::test(JournalEntriesIndex::class)
        // null, not 0 - the KPI card renders null as an em dash and the
        // sub-line names the reason.
        ->assertViewHas('entriesThisFiscalYear', null)
        ->assertSee(__('opes.ledger_screen.kpi_no_open_year'));
});

// ── Truncated registers must say they are truncated ─────────────────────────

it('exposes the statutory book register cap to the view', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(BooksIndex::class)
        ->assertViewHas('listLimit', BooksIndex::LIST_LIMIT)
        ->assertViewHas('hashPrefix', BooksIndex::HASH_PREFIX)
        ->assertViewHas('bookTotal');
});

it('exposes the system documentation snapshot cap to the view', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    Livewire::test(SystemDocumentationIndex::class)
        ->assertViewHas('listLimit', SystemDocumentationIndex::LIST_LIMIT)
        ->assertViewHas('hashPrefix', SystemDocumentationIndex::HASH_PREFIX)
        ->assertViewHas('snapshotTotal');
});

it('shows one hash prefix length on the books screen, not two', function () {
    // The generate() banner and the register column read the SAME constant;
    // two lengths of the same sha256 read to an operator as two hashes.
    expect(BooksIndex::HASH_PREFIX)->toBe(SystemDocumentationIndex::HASH_PREFIX);
});

// ── Defaults must be chronological, not insertion order ────────────────────

it('defaults the books screen to the open fiscal year, not the highest id', function () {
    actingAs(accountingUiUserAs(Role::Accountant));

    // Insertion order is deliberately the REVERSE of chronology: the open year
    // is created first, so it has the LOWEST id. An orderByDesc('id') default
    // would land on the back-filled later year instead.
    $open = FiscalYear::factory()->open()->create([
        'code' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ]);
    FiscalYear::factory()->create([
        'code' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
    ]);

    Livewire::test(BooksIndex::class)
        ->assertSet('fiscalYearId', (string) $open->id);
});
