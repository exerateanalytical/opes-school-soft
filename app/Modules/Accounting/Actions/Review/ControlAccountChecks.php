<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Domain\ControlCheck;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The full control matrix,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * AR and AP are computed - their identity is the auxiliary one, and
 * AuxiliaryControlChecks owns it. The other seven controls (bank, cash,
 * electronic money, payroll, fixed assets, inventory, tax) each need a
 * subledger figure from another module, and this build did not verify how to
 * derive any of them. So they report NotConfigured and say so.
 *
 * THAT IS THE WHOLE POINT. A control with no verified source must never
 * report a zero difference, because a zero difference is a positive claim -
 * it says the books agree. Emitting one from an uncomputed control is the
 * false assurance this subsystem exists to prevent, and it is exactly the
 * bug that shipped in the first version of AuxiliaryControlChecks.
 *
 * Wiring one up is deliberately small: verify the subledger figure, then
 * replace its row here with a real computation.
 *
 * Read-only.
 */
final readonly class ControlAccountChecks
{
    public const PERMISSION = Permission::LedgerView->value;

    /** Controls whose subledger source is not yet wired. */
    public const PENDING = [
        'bank',
        'cash',
        'electronic_money',
        'payroll',
        'fixed_assets',
        'inventory',
        'tax',
    ];

    public function __construct(private AuxiliaryControlChecks $auxiliary) {}

    /**
     * @return Collection<int, ControlCheck>
     */
    public function handle(?string $asOf = null, string $axis = 'fiscal_year'): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        $pending = collect(self::PENDING)->map(
            fn (string $key): ControlCheck => ControlCheck::notConfigured(
                key: $key,
                label: __('opes.accounting.review.control_'.$key),
                gate: __('opes.accounting.review.control_source_pending'),
                axis: $axis,
                asOf: $asOf,
            ),
        );

        return $this->auxiliary->handle($asOf, $axis)->concat($pending)->values();
    }
}
