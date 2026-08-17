<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\ChartOfAccounts;

use App\Modules\Accounting\Actions\ArchiveAccount;
use App\Modules\Accounting\Actions\CreateAccount;
use App\Modules\Accounting\Actions\CreateFiscalYear;
use App\Modules\Accounting\Actions\UpdateAccount;
use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\NormalBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chart of Accounts. docs/specs/02-accounting.md §2, routed at
 * /ledger/chart-of-accounts (routes/web.php, `ledger.chart-of-accounts`).
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
 *
 * Write actions wired here follow `Welfare\Livewire\Visitors\Index`'s
 * toggle-panel pattern: a form is shown/hidden with `showXForm`, `save*()`
 * re-checks `Gate::authorize` (the Action re-checks it again itself - the
 * component's check exists only so the button never appears for someone the
 * gate will reject), calls the Action's `handle()` in a try/catch for
 * `DomainException`/`ValidationException`, flashes `session('status')` on
 * success, and surfaces failures via `$this->addError(...)` - never silently.
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

    // ── Create account form ─────────────────────────────────────────────
    public bool $showCreateForm = false;

    public string $createParentCode = '';

    public string $createCode = '';

    public string $createName = '';

    public string $createNameFr = '';

    public string $createType = '';

    public string $createNormalBalance = '';

    public bool $createIsCollective = false;

    public bool $createIsLettrable = false;

    // ── Edit account form ───────────────────────────────────────────────
    public ?int $editAccountId = null;

    public string $editName = '';

    public string $editNameFr = '';

    public string $editDisplayAlias = '';

    public string $editNotes = '';

    public string $editDsfLineCode = '';

    // ── Create fiscal year form (header toolbar - see class docblock and
    // final report: no other Accounting screen surfaces this). ───────────
    public bool $showFiscalYearForm = false;

    public string $fiscalYearCode = '';

    public string $fiscalYearStartsOn = '';

    public string $fiscalYearEndsOn = '';

    public bool $fiscalYearIsFirstExercice = false;

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

    private function actor(): Actor
    {
        // No `Identity\Models\User` import here on purpose - crossing the
        // module boundary via that Model is exactly what
        // tests/Architecture/ModuleBoundaryTest.php forbids (00-core 6.2).
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }

    public function toggleCreateForm(): void
    {
        Gate::authorize(CreateAccount::PERMISSION);

        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function saveCreateAccount(CreateAccount $createAccount): void
    {
        Gate::authorize(CreateAccount::PERMISSION);

        $validated = $this->validate([
            'createParentCode' => ['required', 'string'],
            'createCode' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
            'createName' => ['required', 'string', 'max:255'],
            'createNameFr' => ['required', 'string', 'max:255'],
            'createType' => ['required', 'string'],
            'createNormalBalance' => ['required', 'string'],
        ]);

        $parent = ChartOfAccount::query()->where('code', $validated['createParentCode'])->first();

        if ($parent === null) {
            $this->addError('createParentCode', __('opes.ledger_screen.coa_parent_not_found'));

            return;
        }

        try {
            $createAccount->handle(
                parentId: (int) $parent->getKey(),
                code: $validated['createCode'],
                name: $validated['createName'],
                nameFr: $validated['createNameFr'],
                type: AccountType::from($validated['createType']),
                normalBalance: NormalBalance::from($validated['createNormalBalance']),
                isCollective: $this->createIsCollective,
                isLettrable: $this->createIsLettrable,
                actor: $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('createCode', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('createCode', $e->getMessage());

            return;
        }

        $this->reset([
            'showCreateForm', 'createParentCode', 'createCode', 'createName', 'createNameFr',
            'createType', 'createNormalBalance', 'createIsCollective', 'createIsLettrable',
        ]);
        session()->flash('status', __('opes.ledger_screen.coa_account_created'));
    }

    public function startEdit(int $accountId): void
    {
        Gate::authorize(UpdateAccount::PERMISSION);

        $account = ChartOfAccount::query()->find($accountId);

        if ($account === null) {
            return;
        }

        $this->editAccountId = (int) $account->getKey();
        $this->editName = $account->name;
        $this->editNameFr = $account->name_fr;
        $this->editDisplayAlias = (string) $account->display_alias;
        $this->editNotes = (string) $account->notes;
        $this->editDsfLineCode = (string) $account->dsf_line_code;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editAccountId', 'editName', 'editNameFr', 'editDisplayAlias', 'editNotes', 'editDsfLineCode']);
    }

    public function saveEditAccount(UpdateAccount $updateAccount): void
    {
        Gate::authorize(UpdateAccount::PERMISSION);

        if ($this->editAccountId === null) {
            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editNameFr' => ['required', 'string', 'max:255'],
        ]);

        $account = ChartOfAccount::query()->find($this->editAccountId);

        if ($account === null) {
            $this->addError('editName', __('opes.ledger_screen.coa_account_not_found'));

            return;
        }

        try {
            $updateAccount->handle(
                account: $account,
                changes: [
                    'name' => $validated['editName'],
                    'name_fr' => $validated['editNameFr'],
                    'display_alias' => $this->editDisplayAlias !== '' ? $this->editDisplayAlias : null,
                    'notes' => $this->editNotes !== '' ? $this->editNotes : null,
                    'dsf_line_code' => $this->editDsfLineCode !== '' ? $this->editDsfLineCode : null,
                ],
                actor: $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('editName', $e->getMessage());

            return;
        }

        $this->reset(['editAccountId', 'editName', 'editNameFr', 'editDisplayAlias', 'editNotes', 'editDsfLineCode']);
        session()->flash('status', __('opes.ledger_screen.coa_account_updated'));
    }

    public function archiveAccount(int $accountId, ArchiveAccount $archiveAccount): void
    {
        Gate::authorize(ArchiveAccount::PERMISSION);

        $account = ChartOfAccount::query()->find($accountId);

        if ($account === null) {
            $this->addError('archive', __('opes.ledger_screen.coa_account_not_found'));

            return;
        }

        try {
            $archiveAccount->handle($account, $this->actor());
        } catch (DomainException $e) {
            $this->addError('archive', $e->getMessage());

            return;
        }

        session()->flash('status', __('opes.ledger_screen.coa_account_archived'));
    }

    public function toggleFiscalYearForm(): void
    {
        Gate::authorize(CreateFiscalYear::PERMISSION);

        $this->showFiscalYearForm = ! $this->showFiscalYearForm;
    }

    public function saveFiscalYear(CreateFiscalYear $createFiscalYear): void
    {
        Gate::authorize(CreateFiscalYear::PERMISSION);

        $validated = $this->validate([
            'fiscalYearCode' => ['required', 'string', 'max:20'],
            'fiscalYearStartsOn' => ['required', 'date'],
            'fiscalYearEndsOn' => ['required', 'date'],
        ]);

        try {
            $createFiscalYear->handle(
                code: $validated['fiscalYearCode'],
                startsOn: $validated['fiscalYearStartsOn'],
                endsOn: $validated['fiscalYearEndsOn'],
                isFirstExercice: $this->fiscalYearIsFirstExercice,
                actor: $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('fiscalYearCode', $e->getMessage());

            return;
        }

        $this->reset(['showFiscalYearForm', 'fiscalYearCode', 'fiscalYearStartsOn', 'fiscalYearEndsOn', 'fiscalYearIsFirstExercice']);
        session()->flash('status', __('opes.ledger_screen.coa_fiscal_year_created'));
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
            'accountTypeOptions' => AccountType::cases(),
            'normalBalanceOptions' => NormalBalance::cases(),
            'canManageAccounts' => Gate::allows(CreateAccount::PERMISSION),
            'canManageFiscalYears' => Gate::allows(CreateFiscalYear::PERMISSION),
        ]);
    }
}
