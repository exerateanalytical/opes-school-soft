<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Review;

use App\Modules\Accounting\Actions\Review\JournalExceptions;
use App\Modules\Accounting\Actions\Review\ResolveSourceDocument;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal exception worklist,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.3.
 *
 * Each row drills to the document that caused it through
 * ResolveSourceDocument (§6.1), so an auditor's "why does this entry exist?"
 * is one click rather than a query. The whole page resolves in ONE batch -
 * cost is bounded by the number of registered resolvers, not the row count.
 */
#[Layout('layouts.app')]
final class Journals extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'draft';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function updatedFilter(string $value): void
    {
        if (! in_array($value, JournalExceptions::FILTERS, true)) {
            $this->filter = 'draft';
        }

        $this->resetPage();
    }

    public function render(): mixed
    {
        $exceptions = app(JournalExceptions::class);

        $entries = $exceptions->query($this->filter)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.accounting.review.journals', [
            'entries' => $entries,
            'counts' => $exceptions->counts(),
            'references' => app(ResolveSourceDocument::class)->forEntryIds(
                $entries->getCollection()->map(fn (JournalEntry $e): int => (int) $e->id)->all(),
            ),
        ]);
    }
}
