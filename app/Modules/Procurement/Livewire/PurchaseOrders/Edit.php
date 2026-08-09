<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\PurchaseOrders;

use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the PO capture screen. The line
 * grid follows the 01-assessment marks-entry discipline: keyboard-first,
 * ALPINE-LOCAL row state, batched save - the grid lives in the browser and
 * `save(rows)` is the ONE request, handing the whole document to
 * CreatePurchaseOrder in a single transaction.
 */
#[Layout('layouts.app')]
final class Edit extends Component
{
    public ?int $supplierId = null;

    public ?int $requisitionId = null;

    public string $orderDate = '';

    public ?string $savedPoNo = null;

    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        $this->orderDate = now()->toDateString();
    }

    /**
     * The single request per save (§10). Rows arrive from the Alpine grid.
     *
     * @param  list<array{description?: string, quantity?: string, unit_price_ht?: int|string, discount_rate_bp?: int|string, expense_account_id?: int|string|null}>  $rows
     */
    public function save(array $rows): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $calendar = $this->currentCalendar();

        $lines = [];

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue; // A blank grid row is not a line.
            }

            $lines[] = [
                'description' => $description,
                'quantity' => (string) ($row['quantity'] ?? '1'),
                'unit_price_ht' => (int) ($row['unit_price_ht'] ?? 0),
                'discount_rate_bp' => (int) ($row['discount_rate_bp'] ?? 0),
                'expense_account_id' => ($row['expense_account_id'] ?? '') === ''
                    ? null
                    : (int) $row['expense_account_id'],
            ];
        }

        if ($this->supplierId === null) {
            throw ValidationException::withMessages(['supplierId' => 'Choose a supplier first.']);
        }

        $po = app(CreatePurchaseOrder::class)->handle(
            [
                'supplier_id' => $this->supplierId,
                'requisition_id' => $this->requisitionId,
                'order_date' => $this->orderDate,
                'academic_year_id' => $calendar['academic_year_id'],
                'fiscal_year_id' => $calendar['fiscal_year_id'],
            ],
            $lines,
            $user->toAuditActor(),
        );

        $this->savedPoNo = $po->po_no;
    }

    /**
     * @return array{academic_year_id: int, fiscal_year_id: int}
     */
    private function currentCalendar(): array
    {
        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id')
            ?? DB::table('academic_years')->orderByDesc('id')->value('id');

        $fiscalYearId = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $this->orderDate)
            ->whereDate('ends_on', '>=', $this->orderDate)
            ->value('id')
            ?? DB::table('fiscal_years')->orderByDesc('id')->value('id');

        if ($academicYearId === null || $fiscalYearId === null) {
            throw ValidationException::withMessages([
                'orderDate' => 'No academic or fiscal year covers this date - configure the calendars first.',
            ]);
        }

        return [
            'academic_year_id' => (int) $academicYearId,
            'fiscal_year_id' => (int) $fiscalYearId,
        ];
    }

    public function render(): mixed
    {
        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name']);

        $expenseAccounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(function ($query): void {
                $query->where('code', 'like', '6%')->orWhere('code', 'like', '2%');
            })
            ->orderBy('code')
            ->limit(400)
            ->get(['id', 'code', 'name']);

        return view('livewire.procurement.purchase-orders.edit', [
            'suppliers' => $suppliers,
            'expenseAccounts' => $expenseAccounts,
        ]);
    }
}
