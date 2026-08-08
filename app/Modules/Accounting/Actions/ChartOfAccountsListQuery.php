<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * A filtered, paginated Chart of Accounts listing for the CoA browser screen
 * (docs/specs/02-accounting.md §2). Plain query-builder Action, not a
 * Domain class: there is no invariant to enforce here, only a read.
 *
 * Read-only: no audit log entry, gated on `ledger.view` only.
 */
final readonly class ChartOfAccountsListQuery
{
    /**
     * @return LengthAwarePaginator<int, ChartOfAccount>
     */
    public function handle(
        ?bool $onlyPostable = null,
        ?int $accountClass = null,
        int $perPage = 25,
        int $page = 1,
    ): LengthAwarePaginator {
        Gate::authorize(Permission::LedgerView->value);

        return ChartOfAccount::query()
            ->when($onlyPostable === true, fn ($query) => $query->where('is_postable', true))
            ->when($accountClass !== null, fn ($query) => $query->where('account_class', $accountClass))
            ->orderBy('code')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
