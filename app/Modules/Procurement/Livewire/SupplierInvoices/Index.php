<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\SupplierInvoices;

use App\Modules\Procurement\Actions\CancelSupplierInvoice;
use App\Modules\Procurement\Actions\IssueSupplierCreditNote;
use App\Modules\Procurement\Domain\SupplierCreditNoteReasonType;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
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
 * docs/specs/03-tax-procurement.md §10 - the supplier-invoice list:
 * search by our FF number or the supplier's own number, filter by status,
 * match exceptions and unresolved withholding surfaced as KPIs (the two
 * states that BLOCK approval). Gated `procurement.invoice_view`.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Cancel row ──────────────────────────────────────────────────────
    public ?int $cancellingId = null;

    public string $cancelReason = '';

    // ── Credit-note form ────────────────────────────────────────────────
    public bool $showCreditNoteForm = false;

    public ?int $creditNoteInvoiceId = null;

    public ?int $creditNoteSupplierId = null;

    public string $creditNoteDate = '';

    public string $creditNoteReasonType = 'return';

    public string $creditNoteReasonNote = '';

    /** @var list<array{description: string, quantity: string, unit_price_ht: int, tax_code_id: ?int, expense_account_id: ?int, supplier_invoice_line_id: ?int}> */
    public array $creditNoteLines = [];

    public function mount(): void
    {
        Gate::authorize(SupplierInvoicePermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function startCancel(int $invoiceId): void
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        $this->cancellingId = $invoiceId;
        $this->cancelReason = '';
    }

    public function cancelCancel(): void
    {
        $this->reset(['cancellingId', 'cancelReason']);
    }

    public function confirmCancel(CancelSupplierInvoice $cancel): void
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        $user = Auth::user();

        if ($user === null || $this->cancellingId === null) {
            return;
        }

        $invoiceId = $this->cancellingId;

        try {
            $cancel->handle($invoiceId, $this->cancelReason, $user->toAuditActor());
        } catch (ValidationException $e) {
            $this->addError('cancelReason', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (DomainException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->reset(['cancellingId', 'cancelReason']);
        session()->flash('status', 'Supplier invoice cancelled.');
    }

    public function toggleCreditNoteForm(): void
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        $this->showCreditNoteForm = ! $this->showCreditNoteForm;

        if ($this->showCreditNoteForm) {
            if ($this->creditNoteDate === '') {
                $this->creditNoteDate = now()->toDateString();
            }

            if ($this->creditNoteLines === []) {
                $this->addCreditNoteLine();
            }
        }
    }

    public function updatedCreditNoteInvoiceId(): void
    {
        if ($this->creditNoteInvoiceId === null) {
            return;
        }

        /** @var object{supplier_id: int}|null $invoice */
        // where('id') not whereKey(): a DB::table() query builder has no
        // whereKey, and the magic where{Column} fallback turns it into
        // `where 'key' = ?`.
        $invoice = DB::table('supplier_invoices')->where('id', $this->creditNoteInvoiceId)->first(['supplier_id']);

        if ($invoice !== null) {
            $this->creditNoteSupplierId = (int) $invoice->supplier_id;
        }
    }

    public function addCreditNoteLine(): void
    {
        $this->creditNoteLines[] = [
            'description' => '',
            'quantity' => '1',
            'unit_price_ht' => 0,
            'tax_code_id' => null,
            'expense_account_id' => null,
            'supplier_invoice_line_id' => null,
        ];
    }

    public function removeCreditNoteLine(int $index): void
    {
        unset($this->creditNoteLines[$index]);
        $this->creditNoteLines = array_values($this->creditNoteLines);
    }

    public function saveCreditNote(IssueSupplierCreditNote $issue): void
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $lines = [];

        foreach ($this->creditNoteLines as $line) {
            $description = trim((string) $line['description']);

            if ($description === '') {
                continue;
            }

            $lines[] = [
                'description' => $description,
                'quantity' => (string) $line['quantity'],
                'unit_price_ht' => (int) $line['unit_price_ht'],
                'tax_code_id' => $line['tax_code_id'] !== null ? (int) $line['tax_code_id'] : null,
                'expense_account_id' => $line['expense_account_id'] !== null ? (int) $line['expense_account_id'] : null,
                'supplier_invoice_line_id' => $line['supplier_invoice_line_id'] !== null ? (int) $line['supplier_invoice_line_id'] : null,
            ];
        }

        $header = [
            'credit_note_date' => $this->creditNoteDate,
            'reason_type' => $this->creditNoteReasonType,
            'reason_note' => $this->creditNoteReasonNote,
        ];

        if ($this->creditNoteInvoiceId !== null) {
            $header['original_invoice_id'] = $this->creditNoteInvoiceId;
        }

        if ($this->creditNoteSupplierId !== null) {
            $header['supplier_id'] = $this->creditNoteSupplierId;
        }

        try {
            $issue->handle($header, $lines, $user->toAuditActor());
        } catch (DomainException $e) {
            $this->addError('creditNote', $e->getMessage());

            return;
        }

        $this->reset([
            'showCreditNoteForm', 'creditNoteInvoiceId', 'creditNoteSupplierId', 'creditNoteDate',
            'creditNoteReasonType', 'creditNoteReasonNote', 'creditNoteLines',
        ]);
        $this->page = 1;
        session()->flash('status', 'Supplier credit note issued.');
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('supplier_invoices as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id');

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('i.internal_no', 'like', $term)
                    ->orWhere('i.supplier_invoice_no', 'like', $term)
                    ->orWhere('s.name', 'like', $term);
            });
        }

        if ($this->status !== '') {
            $query->where('i.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'i.id', 'i.internal_no', 'i.supplier_invoice_no', 'i.invoice_date', 'i.due_date',
                'i.total_ttc', 'i.withholding_total', 'i.net_payable', 'i.status', 'i.match_status',
                'i.withholding_unresolved', 's.name as supplier_name',
            ])
            ->orderByDesc('i.invoice_date')
            ->orderByDesc('i.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'pending_approval' => DB::table('supplier_invoices')->where('status', 'pending_approval')->count(),
            'match_exceptions' => DB::table('supplier_invoices')->where('status', 'match_exception')->count(),
            'withholding_unresolved' => DB::table('supplier_invoices')
                ->where('withholding_unresolved', true)
                ->whereNotIn('status', ['posted', 'partially_paid', 'paid', 'cancelled'])
                ->count(),
            'posted' => DB::table('supplier_invoices')->where('status', 'posted')->count(),
        ];

        $postedInvoices = DB::table('supplier_invoices')
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->get(['id', 'internal_no', 'supplier_id']);

        $creditNoteInvoiceLines = $this->creditNoteInvoiceId !== null
            ? DB::table('supplier_invoice_lines')
                ->where('supplier_invoice_id', $this->creditNoteInvoiceId)
                ->orderBy('line_no')
                ->get(['id', 'line_no', 'description'])
            : collect();

        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name']);

        $taxCodes = DB::table('tax_codes')->orderBy('code')->limit(100)->get(['id', 'code', 'rate_bp']);

        $expenseAccounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(function ($query): void {
                $query->where('code', 'like', '6%')->orWhere('code', 'like', '2%');
            })
            ->orderBy('code')
            ->limit(400)
            ->get(['id', 'code', 'name']);

        return view('livewire.procurement.supplier-invoices.index', [
            'invoices' => $paginator,
            'kpis' => $kpis,
            'canManageInvoices' => Gate::allows(SupplierInvoicePermission::CREATE),
            'postedInvoices' => $postedInvoices,
            'creditNoteInvoiceLines' => $creditNoteInvoiceLines,
            'suppliers' => $suppliers,
            'taxCodes' => $taxCodes,
            'expenseAccounts' => $expenseAccounts,
            'creditNoteReasonTypes' => array_map(static fn (SupplierCreditNoteReasonType $r): string => $r->value, SupplierCreditNoteReasonType::cases()),
        ]);
    }
}
