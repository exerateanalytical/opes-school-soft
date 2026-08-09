<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the F2 procurement suites. Prefix f2Proc, every
 * helper function_exists-guarded (Pest loads helper files once per suite
 * but these names must never collide with another agent's).
 *
 * The procurement.* permissions are granted DIRECTLY (Spatie rows), not
 * through RolePermissionSeeder: the Phase 5 wiring package (F5) owns the
 * Permission enum cases and role mapping, while these suites exercise the
 * Actions' own gates.
 */
if (! function_exists('f2ProcUser')) {
    /** A signed-in user holding exactly the named procurement abilities. */
    function f2ProcUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('f2ProcActor')) {
    function f2ProcActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('f2ProcPayableAccountId')) {
    /** The seeded collective 401 - the operating payables default. */
    function f2ProcPayableAccountId(): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', '401')->value('id');
    }
}

if (! function_exists('f2ProcExpenseAccountId')) {
    /** A postable class-6 account for requisition/PO lines. */
    function f2ProcExpenseAccountId(): int
    {
        $id = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where('code', 'like', '6%')
            ->orderBy('code')
            ->value('id');

        return (int) $id;
    }
}

if (! function_exists('f2ProcSupplier')) {
    /**
     * A supplier saved through the REAL SaveSupplier gate (manager user
     * created on the fly when none given).
     *
     * @param  array<string, mixed>  $overrides
     */
    function f2ProcSupplier(array $overrides = [], ?User $manager = null): Supplier
    {
        $manager ??= f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

        return app(SaveSupplier::class)->handle($overrides + [
            'name' => 'Librairie Centrale '.fake()->unique()->numberBetween(1, 999_999),
            'supplier_type' => 'company',
            'payable_account_id' => f2ProcPayableAccountId(),
        ], f2ProcActor($manager));
    }
}

if (! function_exists('f2ProcCalendar')) {
    /**
     * Fiscal + academic year covering the given date (Accounting's own
     * factory builder, same as ledgerCalendar but under the f2 prefix).
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function f2ProcCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(\Illuminate\Support\Carbon::parse($date));
    }
}

if (! function_exists('f2ProcRequisition')) {
    /**
     * A draft requisition with one stationery line (40 x 3250 = 130 000).
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @param  array<string, mixed>  $headerOverrides
     */
    function f2ProcRequisition(User $requester, array $calendar, array $headerOverrides = []): \App\Modules\Procurement\Models\PurchaseRequisition
    {
        return app(\App\Modules\Procurement\Actions\SaveRequisition::class)->handle(
            $headerOverrides + [
                'requested_on' => '2031-03-10',
                'justification' => 'Term 2 stationery',
                'academic_year_id' => $calendar['academic_year_id'],
                'fiscal_year_id' => $calendar['fiscal_year_id'],
            ],
            [
                [
                    'description' => 'Reams of A4 paper',
                    'quantity' => '40',
                    'estimated_unit_price' => 3_250,
                    'expense_account_id' => f2ProcExpenseAccountId(),
                ],
            ],
            f2ProcActor($requester),
        );
    }
}

if (! function_exists('f2ProcPurchaseOrder')) {
    /**
     * A draft PO for the given supplier: 40 reams at 3 250 HT = 130 000.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @param  array<string, mixed>  $headerOverrides
     * @param  list<array{description: string, quantity: string, unit_price_ht: int, discount_rate_bp?: int, tax_code_id?: int|null, expense_account_id?: int|null, requisition_line_id?: int|null, is_capitalised?: bool, inventory_item_id?: int|null, asset_category_id?: int|null, unit_of_measure?: string|null}>|null  $lines
     */
    function f2ProcPurchaseOrder(
        User $creator,
        Supplier $supplier,
        array $calendar,
        array $headerOverrides = [],
        ?array $lines = null,
    ): \App\Modules\Procurement\Models\PurchaseOrder {
        return app(\App\Modules\Procurement\Actions\CreatePurchaseOrder::class)->handle(
            $headerOverrides + [
                'supplier_id' => $supplier->id,
                'order_date' => '2031-03-12',
                'academic_year_id' => $calendar['academic_year_id'],
                'fiscal_year_id' => $calendar['fiscal_year_id'],
            ],
            $lines ?? [
                [
                    'description' => 'Reams of A4 paper',
                    'quantity' => '40',
                    'unit_price_ht' => 3_250,
                    'expense_account_id' => f2ProcExpenseAccountId(),
                ],
            ],
            f2ProcActor($creator),
        );
    }
}
