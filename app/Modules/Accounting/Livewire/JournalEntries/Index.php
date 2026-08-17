<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\JournalEntries;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Journal entries list, docs/specs/02-accounting.md §4.1. Routed at
 * /ledger/journal-entries (routes/web.php, `ledger.journal-entries.index`).
 *
 * D5's `GeneralLedgerQuery` was not yet on disk when this component was
 * authored, so the list queries `JournalEntry` (D3) directly, the same
 * pattern `Identity\Livewire\Users\Index` uses. `JournalEntry` carries no
 * `journal()` relation (it is persistence-only per its docblock), so journals
 * are loaded once and looked up by id rather than eager-loaded.
 *
 * `reverseEntry()` wires D5's `ReverseJournalEntry` per-row on a posted
 * entry, following `Welfare\Livewire\Visitors\Index`'s inline-form pattern:
 * a reason is collected in a small per-row panel (never a bare confirm -
 * §9.2 requires a reason, and `ReverseJournalEntry` itself throws a
 * `ValidationException` below 10 characters), the Blade button additionally
 * carries `wire:confirm` because reversing a posted entry is exactly the
 * kind of significant, hard-to-casually-undo action that pattern is for.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $journalId = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Reverse entry (per-row) ─────────────────────────────────────────
    public ?int $reversingEntryId = null;

    public string $reverseReason = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['journalId', 'status', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function updatedJournalId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function actor(): Actor
    {
        // No `Identity\Models\User` import here on purpose - crossing the
        // module boundary via that Model is exactly what
        // tests/Architecture/ModuleBoundaryTest.php forbids (00-core 6.2).
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }

    public function startReverse(int $entryId): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $this->reversingEntryId = $entryId;
        $this->reverseReason = '';
    }

    public function cancelReverse(): void
    {
        $this->reset(['reversingEntryId', 'reverseReason']);
    }

    public function reverseEntry(ReverseJournalEntry $reverseJournalEntry): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        if ($this->reversingEntryId === null) {
            return;
        }

        try {
            $reverseJournalEntry->handle(
                entryId: $this->reversingEntryId,
                reason: $this->reverseReason,
                actor: $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->reset(['reversingEntryId', 'reverseReason']);
        session()->flash('status', __('opes.ledger_screen.je_reversed'));
    }

    /**
     * @return Builder<JournalEntry>
     */
    private function baseQuery(): Builder
    {
        return JournalEntry::query()
            ->when($this->journalId !== '', function (Builder $query): void {
                $query->where('journal_id', (int) $this->journalId);
            })
            ->when($this->status !== '', function (Builder $query): void {
                $query->where('status', $this->status);
            })
            ->when($this->dateFrom !== '', function (Builder $query): void {
                $query->whereDate('date', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function (Builder $query): void {
                $query->whereDate('date', '<=', $this->dateTo);
            });
    }

    /**
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    private function entries(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    /**
     * @return Collection<int, Journal>
     */
    private function journals(): Collection
    {
        return Journal::query()->orderBy('code')->get()->keyBy('id');
    }

    public function render(): mixed
    {
        $entries = $this->entries();
        $currentFiscalYear = FiscalYear::query()->where('status', 'open')->first();

        return view('livewire.accounting.journal-entries.index', [
            'entries' => $entries,
            'journalOptions' => $this->journals(),
            // "This period" in the task brief is read here as the currently
            // open fiscal year - AccountingPeriod (the calendar-month grain)
            // is not this pass's model to query, and the KPI label says
            // "fiscal year" rather than overclaiming month-level precision.
            //
            // NULL, not 0, when no year is open: "no year is open" and "the
            // open year has no posted entries" are different facts, and the
            // KPI card renders null as an em dash rather than a confident
            // zero (09-ui §3.3). The reason is named in the card's sub-line.
            'entriesThisFiscalYear' => $currentFiscalYear === null ? null : JournalEntry::query()
                ->where('fiscal_year_id', $currentFiscalYear->id)
                ->postedLedger()
                ->count(),
            'entriesYearReason' => $currentFiscalYear === null
                ? __('opes.ledger_screen.kpi_no_open_year')
                : null,
            'unpostedDrafts' => JournalEntry::query()->where('status', JournalEntry::STATUS_DRAFT)->count(),
            'canReverse' => Gate::allows(Permission::LedgerPost->value),
        ]);
    }
}
