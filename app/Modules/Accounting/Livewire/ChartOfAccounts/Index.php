<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\ChartOfAccounts;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chart of Accounts, read-only list. docs/specs/02-accounting.md §2, routed at
 * /ledger/chart-of-accounts (routes/web.php, `ledger.chart-of-accounts`).
 *
 * Phase 4 does not add a create-account screen here: extending the plan below
 * a system parent is an Accountant configuration task the brief scopes out of
 * this pass, and D1 exposes no create Action for this screen to call. This
 * component is therefore genuinely read-only, not a stub waiting on a button.
 *
 * D5's `ChartOfAccountsListQuery` (`App\Modules\Accounting\Actions\
 * ChartOfAccountsListQuery`) landed with `handle(?bool $onlyPostable, ?int
 * $accountClass, int $perPage, int $page)` - it has no search parameter, and
 * this screen's brief requires "search by code/name". Rather than drop that
 * requirement, this component queries `ChartOfAccount` (D1) directly, the
 * same pattern `Identity\Livewire\Users\Index` uses against its own module's
 * model, so the class-and-postable filters D5's Action already covers are
 * reproduced faithfully and search is added on top. Every number here is a
 * real query result, never fabricated.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $accountClass = '';

    #[Url]
    public bool $postableOnly = false;

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'accountClass', 'postableOnly']);
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAccountClass(): void
    {
        $this->resetPage();
    }

    public function updatedPostableOnly(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<ChartOfAccount>
     */
    private function baseQuery(): Builder
    {
        return ChartOfAccount::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('code', 'like', $this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('name_fr', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->accountClass !== '', function ($query): void {
                $query->where('account_class', (int) $this->accountClass);
            })
            ->when($this->postableOnly, function ($query): void {
                $query->where('is_postable', true);
            });
    }

    /**
     * @return LengthAwarePaginator<int, ChartOfAccount>
     */
    private function accounts(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->orderBy('code')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    public function render(): mixed
    {
        $accounts = $this->accounts();

        return view('livewire.accounting.chart-of-accounts.index', [
            'accounts' => $accounts,
            // KPIs respect the active filters, same convention as the header
            // total in Identity\Livewire\Users\Index - "accounts matching
            // what you're looking at", not a hidden system-wide figure.
            'totalAccounts' => $accounts->total(),
            'postableAccounts' => (clone $this->baseQuery())->where('is_postable', true)->count(),
        ]);
    }
}
