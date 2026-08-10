<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\GoodsReceipts;

use App\Modules\Procurement\Actions\ConfirmGoodsReceipt;
use App\Modules\Procurement\Actions\SaveGoodsReceipt;
use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use App\Modules\Procurement\Domain\ProcurementPermission;
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
 * docs/specs/03-tax-procurement.md §10 - the goods-receipt list, with the
 * discrepancy flag surfaced: a receipt with rejected quantities is a
 * blocked three-way match until its credit note or amendment arrives.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── New-receipt form ────────────────────────────────────────────────
    public bool $showForm = false;

    public ?int $formSupplierId = null;

    public ?int $formPurchaseOrderId = null;

    public string $formReceivedOn = '';

    public string $formDeliveryNoteRef = '';

    /** @var list<array{description: string, qty_received: string, qty_rejected: string, rejection_reason: string, purchase_order_line_id: ?int}> */
    public array $formLines = [];

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

    public function toggleForm(): void
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        $this->showForm = ! $this->showForm;

        if ($this->showForm) {
            if ($this->formReceivedOn === '') {
                $this->formReceivedOn = now()->toDateString();
            }

            if ($this->formLines === []) {
                $this->addLine();
            }
        }
    }

    public function updatedFormPurchaseOrderId(): void
    {
        if ($this->formPurchaseOrderId === null) {
            return;
        }

        /** @var object{supplier_id: int}|null $po */
        $po = DB::table('purchase_orders')->whereKey($this->formPurchaseOrderId)->first(['supplier_id']);

        if ($po !== null) {
            $this->formSupplierId = (int) $po->supplier_id;
        }
    }

    public function addLine(): void
    {
        $this->formLines[] = [
            'description' => '',
            'qty_received' => '1',
            'qty_rejected' => '0',
            'rejection_reason' => '',
            'purchase_order_line_id' => null,
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->formLines[$index]);
        $this->formLines = array_values($this->formLines);
    }

    public function save(SaveGoodsReceipt $save): void
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        $user = Auth::user();

        if ($user === null) {
            return;
        }

        if ($this->formSupplierId === null) {
            $this->addError('formSupplierId', 'Choose a supplier first.');

            return;
        }

        $lines = [];

        foreach ($this->formLines as $line) {
            $description = trim((string) $line['description']);

            if ($description === '' && ($line['purchase_order_line_id'] ?? null) === null) {
                continue;
            }

            $lines[] = [
                'description' => $description === '' ? null : $description,
                'qty_received' => (string) $line['qty_received'],
                'qty_rejected' => (string) ($line['qty_rejected'] ?? '0'),
                'rejection_reason' => trim((string) ($line['rejection_reason'] ?? '')) === '' ? null : trim((string) $line['rejection_reason']),
                'purchase_order_line_id' => $line['purchase_order_line_id'] !== null ? (int) $line['purchase_order_line_id'] : null,
            ];
        }

        $calendar = $this->currentCalendar();

        try {
            $save->handle(
                [
                    'supplier_id' => $this->formSupplierId,
                    'purchase_order_id' => $this->formPurchaseOrderId,
                    'received_on' => $this->formReceivedOn,
                    'delivery_note_ref' => $this->formDeliveryNoteRef === '' ? null : $this->formDeliveryNoteRef,
                    'academic_year_id' => $calendar['academic_year_id'],
                    'fiscal_year_id' => $calendar['fiscal_year_id'],
                ],
                $lines,
                $user->toAuditActor(),
            );
        } catch (ValidationException $e) {
            $this->addError('formLines', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (DomainException $e) {
            $this->addError('formLines', $e->getMessage());

            return;
        }

        $this->reset(['showForm', 'formSupplierId', 'formPurchaseOrderId', 'formReceivedOn', 'formDeliveryNoteRef', 'formLines']);
        $this->page = 1;
        session()->flash('status', 'Goods receipt recorded as draft.');
    }

    public function confirm(int $goodsReceiptId, ConfirmGoodsReceipt $confirm): void
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        $user = Auth::user();

        if ($user === null) {
            return;
        }

        try {
            $confirm->handle($goodsReceiptId, $user->toAuditActor());
        } catch (ValidationException $e) {
            $this->addError('receipt-'.$goodsReceiptId, (string) collect($e->errors())->flatten()->first());

            return;
        } catch (DomainException $e) {
            $this->addError('receipt-'.$goodsReceiptId, $e->getMessage());

            return;
        }

        session()->flash('status', 'Goods receipt confirmed.');
    }

    /**
     * @return array{academic_year_id: int, fiscal_year_id: int}
     */
    private function currentCalendar(): array
    {
        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id')
            ?? DB::table('academic_years')->orderByDesc('id')->value('id');

        $fiscalYearId = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $this->formReceivedOn !== '' ? $this->formReceivedOn : now()->toDateString())
            ->whereDate('ends_on', '>=', $this->formReceivedOn !== '' ? $this->formReceivedOn : now()->toDateString())
            ->value('id')
            ?? DB::table('fiscal_years')->orderByDesc('id')->value('id');

        if ($academicYearId === null || $fiscalYearId === null) {
            throw ValidationException::withMessages([
                'formReceivedOn' => 'No academic or fiscal year covers this date - configure the calendars first.',
            ]);
        }

        return [
            'academic_year_id' => (int) $academicYearId,
            'fiscal_year_id' => (int) $fiscalYearId,
        ];
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('goods_receipts as gr')
            ->join('suppliers as s', 's.id', '=', 'gr.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id');

        if (GoodsReceiptStatus::tryFrom($this->status) !== null) {
            $query->where('gr.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.status', 'gr.has_discrepancy',
                'gr.delivery_note_ref', 's.name as supplier_name', 'po.po_no',
            ])
            ->orderByDesc('gr.received_on')
            ->orderByDesc('gr.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'draft' => DB::table('goods_receipts')->where('status', 'draft')->count(),
            'discrepancies' => DB::table('goods_receipts')->where('has_discrepancy', true)->count(),
        ];

        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name']);

        $purchaseOrders = DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'sent', 'partially_received'])
            ->orderByDesc('order_date')
            ->limit(200)
            ->get(['id', 'po_no', 'supplier_id']);

        $poLines = $this->formPurchaseOrderId !== null
            ? DB::table('purchase_order_lines')
                ->where('purchase_order_id', $this->formPurchaseOrderId)
                ->orderBy('line_no')
                ->get(['id', 'line_no', 'description', 'quantity', 'qty_received'])
            : collect();

        return view('livewire.procurement.goods-receipts.index', [
            'receipts' => $paginator,
            'kpis' => $kpis,
            'statusOptions' => array_map(static fn (GoodsReceiptStatus $s): string => $s->value, GoodsReceiptStatus::cases()),
            'suppliers' => $suppliers,
            'purchaseOrders' => $purchaseOrders,
            'poLines' => $poLines,
            'canManage' => Gate::allows(ProcurementPermission::ORDER_MANAGE),
        ]);
    }
}
