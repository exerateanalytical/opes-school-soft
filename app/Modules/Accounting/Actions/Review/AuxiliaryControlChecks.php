<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Actions\ReconcileAuxiliaryBalances;
use App\Modules\Accounting\Domain\ControlCheck;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * AR <-> GL and AP <-> GL for the Control Centre,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * THIS CLASS COMPUTES NOTHING. The L9 identity - "Σ auxiliary balances per
 * collective account = that account's GL balance" (02-accounting.md §8.4) -
 * has exactly ONE implementation, `ReconcileAuxiliaryBalances`, which carries
 * the spec's §8.4 queries verbatim. This Action presents those rows as the
 * `ControlCheck` value object the rest of the review subsystem speaks.
 *
 * Why that matters more than it looks. A second implementation was written
 * here first, summing a collective account's lines and then summing the same
 * lines filtered to `partner_id IS NOT NULL`. Because L8 (§8.3, enforced by
 * an unconditional trigger) guarantees every line on a collective account
 * carries a partner, those two sums are over the SAME ROWS - the check could
 * only ever return zero. It would have reported "Reconciled" for a genuinely
 * broken ledger, which is the precise failure mode this whole subsystem
 * exists to prevent. The real identity groups by partner first, which is what
 * the delegated Action does.
 *
 * Read-only. It presents; it never decides and never writes.
 */
final readonly class AuxiliaryControlChecks
{
    public const PERMISSION = Permission::LedgerView->value;

    public function __construct(private ReconcileAuxiliaryBalances $reconcile) {}

    /**
     * @return Collection<int, ControlCheck>
     */
    public function handle(?string $asOf = null, string $axis = 'fiscal_year'): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        $rows = $this->reconcile->handle($asOf);

        // One lookup for every label, rather than one per row.
        $names = ChartOfAccount::query()
            ->whereIn('id', $rows->pluck('account_id')->all())
            ->pluck('name', 'id');

        return $rows
            ->sortBy('code')
            ->map(fn (object $row): ControlCheck => ControlCheck::reconciledOrBroken(
                key: 'auxiliary_'.$row->code,
                label: trim($row->code.' '.($names[$row->account_id] ?? '')),
                expected: (int) $row->gl_balance,
                actual: (int) $row->auxiliary_sum,
                axis: $axis,
                asOf: $asOf,
            ))
            ->values();
    }
}
