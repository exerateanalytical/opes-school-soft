<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Reports;

use App\Modules\Accounting\Actions\TrialBalance as TrialBalanceQuery;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Balance générale (trial balance), docs/specs/02-accounting.md §9.3 / L13.
 * Routed at /ledger/trial-balance (routes/web.php, `ledger.trial-balance`).
 *
 * Delegates entirely to D5's `App\Modules\Accounting\Actions\TrialBalance`
 * (aliased `TrialBalanceQuery` here to avoid colliding with this class's own
 * name), which already builds the balance through the one permitted read
 * path - `JournalEntry::scopePostedLedger()` - per §9.3's rule that every
 * statement includes both `posted` and `reversed` entries so they net to
 * zero, never `status = 'posted'` alone. Grand totals are asserted equal in
 * the view; §9.3/L2 guarantee they always are for a ledger with no integrity
 * fault, so a mismatch here is a real upstream bug to report, not something
 * to round away.
 */
#[Layout('layouts.app')]
final class TrialBalance extends Component
{
    #[Url]
    public string $fiscalYearId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->fiscalYearId === '') {
            $openYear = FiscalYear::query()->where('status', 'open')->first();
            $this->fiscalYearId = $openYear === null ? '' : (string) $openYear->id;
        }
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    private function fiscalYears(): Collection
    {
        return FiscalYear::query()->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, object{account_id: int, code: string, name: string, name_fr: string, total_debit: int, total_credit: int}>
     */
    private function rows(TrialBalanceQuery $query): Collection
    {
        if ($this->fiscalYearId === '') {
            return collect();
        }

        return $query->handle((int) $this->fiscalYearId);
    }

    public function render(): mixed
    {
        $rows = $this->rows(app(TrialBalanceQuery::class));

        return view('livewire.accounting.reports.trial-balance', [
            'fiscalYearOptions' => $this->fiscalYears(),
            'rows' => $rows,
            'totalDebit' => $rows->sum('total_debit'),
            'totalCredit' => $rows->sum('total_credit'),
        ]);
    }
}
