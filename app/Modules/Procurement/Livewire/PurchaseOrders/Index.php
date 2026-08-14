<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\PurchaseOrders;

use App\Modules\Procurement\Actions\AmendPurchaseOrder;
use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\CancelPurchaseOrder;
use App\Modules\Procurement\Actions\ClosePurchaseOrder;
use App\Modules\Procurement\Actions\SendPurchaseOrder;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the purchase-order list. Open
 * commitments are the KPI that matters: approved-but-not-fully-received
 * value is exactly what the year-end 4818 cut-off will interrogate.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    /** The order currently open for amendment, or null when the panel is closed. */
    public ?int $amendingId = null;

    public string $amendReason = '';

    public int $amendExpectedVersion = 0;

    /** @var list<array{line_no: int|null, description: string, quantity: string, unit_price_ht: int, discount_rate_bp: int, expense_account_id: ?int}> */
    public array $amendLines = [];

    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['status']);
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function approve(int $purchaseOrderId, ApprovePurchaseOrder $approve): void
    {
        $this->act($purchaseOrderId, fn ($actor) => $approve->handle($purchaseOrderId, $actor), 'Purchase order approved.');
    }

    public function send(int $purchaseOrderId, SendPurchaseOrder $send): void
    {
        $this->act($purchaseOrderId, fn ($actor) => $send->handle($purchaseOrderId, $actor), 'Purchase order sent.');
    }

    public function cancel(int $purchaseOrderId, CancelPurchaseOrder $cancel): void
    {
        $this->act($purchaseOrderId, fn ($actor) => $cancel->handle($purchaseOrderId, $actor), 'Purchase order cancelled.');
    }

    public function close(int $purchaseOrderId, ClosePurchaseOrder $close): void
    {
        $this->act($purchaseOrderId, fn ($actor) => $close->handle($purchaseOrderId, $actor), 'Purchase order closed.');
    }

    public function startAmend(int $purchaseOrderId): void
    {
        Gate::authorize(ProcurementPermission::ORDER_APPROVE);

        /** @var object{version: int}|null $po */
        // NOT whereKey(): DB::table() returns a Query builder, which has no
        // such method, so Laravel's dynamic where{Column} magic rewrites it to
        // `where 'key' = ?` and the click 500s. Eloquent builders have
        // whereKey(); query builders never do.
        $po = DB::table('purchase_orders')->where('id', $purchaseOrderId)->first(['version']);

        if ($po === null) {
            return;
        }

        $lines = DB::table('purchase_order_lines')
            ->where('purchase_order_id', $purchaseOrderId)
            ->orderBy('line_no')
            ->get(['line_no', 'description', 'quantity', 'unit_price_ht', 'discount_rate_bp', 'expense_account_id']);

        $this->amendingId = $purchaseOrderId;
        $this->amendExpectedVersion = (int) $po->version;
        $this->amendReason = '';
        $this->amendLines = $lines->map(static fn ($line): array => [
            'line_no' => (int) $line->line_no,
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price_ht' => (int) $line->unit_price_ht,
            'discount_rate_bp' => (int) $line->discount_rate_bp,
            'expense_account_id' => $line->expense_account_id !== null ? (int) $line->expense_account_id : null,
        ])->all();
    }

    public function cancelAmend(): void
    {
        $this->reset(['amendingId', 'amendReason', 'amendExpectedVersion', 'amendLines']);
    }

    public function addAmendLine(): void
    {
        $this->amendLines[] = [
            'line_no' => null,
            'description' => '',
            'quantity' => '1',
            'unit_price_ht' => 0,
            'discount_rate_bp' => 0,
            'expense_account_id' => null,
        ];
    }

    public function removeAmendLine(int $index): void
    {
        unset($this->amendLines[$index]);
        $this->amendLines = array_values($this->amendLines);
    }

    public function saveAmendment(AmendPurchaseOrder $amend): void
    {
        $user = Auth::user();

        if ($user === null || $this->amendingId === null) {
            return;
        }

        $purchaseOrderId = $this->amendingId;

        $lines = [];

        foreach ($this->amendLines as $line) {
            $description = trim((string) $line['description']);

            if ($description === '') {
                continue;
            }

            $lines[] = [
                'line_no' => $line['line_no'],
                'description' => $description,
                'quantity' => (string) $line['quantity'],
                'unit_price_ht' => (int) $line['unit_price_ht'],
                'discount_rate_bp' => (int) $line['discount_rate_bp'],
                'expense_account_id' => (int) $line['expense_account_id'],
            ];
        }

        try {
            $amend->handle($purchaseOrderId, $this->amendReason, $lines, $this->amendExpectedVersion, $user->toAuditActor());
        } catch (ValidationException $e) {
            $this->addError('amend', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (DomainException $e) {
            $this->addError('amend', $e->getMessage());

            return;
        }

        $this->reset(['amendingId', 'amendReason', 'amendExpectedVersion', 'amendLines']);
        session()->flash('status', 'Purchase order amended.');
    }

    /**
     * @param  callable(\App\Support\Audit\Actor): mixed  $action
     */
    private function act(int $purchaseOrderId, callable $action, string $message): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        try {
            $action($user->toAuditActor());
        } catch (ValidationException $e) {
            $this->addError('order-'.$purchaseOrderId, (string) collect($e->errors())->flatten()->first());

            return;
        } catch (DomainException $e) {
            $this->addError('order-'.$purchaseOrderId, $e->getMessage());

            return;
        }

        session()->flash('status', $message);
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id');

        if (PurchaseOrderStatus::tryFrom($this->status) !== null) {
            $query->where('po.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'po.id', 'po.po_no', 'po.order_date', 'po.status', 'po.total_ttc',
                'po.expected_delivery_date', 's.name as supplier_name', 's.code as supplier_code',
            ])
            ->orderByDesc('po.order_date')
            ->orderByDesc('po.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $open = (int) DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'sent', 'partially_received'])
            ->sum('total_ttc');

        $expenseAccounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(function ($query): void {
                $query->where('code', 'like', '6%')->orWhere('code', 'like', '2%');
            })
            ->orderBy('code')
            ->limit(400)
            ->get(['id', 'code', 'name']);

        return view('livewire.procurement.purchase-orders.index', [
            'orders' => $paginator,
            'openCommitments' => $open,
            'expenseAccounts' => $expenseAccounts,
            'draftCount' => DB::table('purchase_orders')->where('status', 'draft')->count(),
            'statusOptions' => array_map(static fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderStatus::cases()),
            'canApprove' => Gate::allows(ProcurementPermission::ORDER_APPROVE),
            'canManage' => Gate::allows(ProcurementPermission::ORDER_MANAGE),
        ]);
    }
}
