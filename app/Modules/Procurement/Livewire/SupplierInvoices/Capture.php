<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\SupplierInvoices;

use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\CaptureSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\OverrideMatchException;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - capture a facture fournisseur:
 * keyboard-first line grid (Alpine-local rows, ONE request on save), then
 * the match panel (PO ↔ receipt ↔ invoice per line, variances highlighted)
 * and the tax panel (per-line TVA split and withholding with the applied
 * rule named), then approve / post.
 *
 * The component drives the REAL Actions - capture, match, override,
 * approve, post - and never computes a franc itself.
 */
#[Layout('layouts.app')]
final class Capture extends Component
{
    #[Url(as: 'invoice')]
    public ?int $invoiceId = null;

    // ── Header ──────────────────────────────────────────────────────────
    public string $supplierId = '';

    public string $supplierInvoiceNo = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $purchaseOrderId = '';

    /** @var list<array{description: string, quantity: string, unit_price_ht: string, discount_rate_bp: string, tax_code_id: string, expense_account_id: string, purchase_order_line_id: string}> */
    public array $rows = [];

    public string $overrideReason = '';

    public string $unmatchedReason = '';

    public string $waiveReason = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        Gate::authorize(SupplierInvoicePermission::VIEW);

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'description' => '',
            'quantity' => '1',
            'unit_price_ht' => '',
            'discount_rate_bp' => '0',
            'tax_code_id' => '',
            'expense_account_id' => '',
            'purchase_order_line_id' => '',
        ];
    }

    public function removeRow(int $index): void
    {
        $rows = [];

        foreach ($this->rows as $i => $row) {
            if ($i !== $index) {
                $rows[] = $row;
            }
        }

        $this->rows = $rows;
    }

    /** Capture + immediately run the §4.4 match - ONE request. */
    public function save(): void
    {
        $this->error = null;
        $this->notice = null;

        $lines = [];

        foreach ($this->rows as $row) {
            if (trim($row['description']) === '' && trim($row['unit_price_ht']) === '') {
                continue;
            }

            $lines[] = [
                'description' => trim($row['description']),
                'quantity' => trim($row['quantity']) === '' ? '1' : trim($row['quantity']),
                'unit_price_ht' => (int) $row['unit_price_ht'],
                'discount_rate_bp' => (int) $row['discount_rate_bp'],
                'tax_code_id' => (int) $row['tax_code_id'],
                'expense_account_id' => (int) $row['expense_account_id'],
                'purchase_order_line_id' => $row['purchase_order_line_id'] === '' ? null : (int) $row['purchase_order_line_id'],
            ];
        }

        try {
            $user = Auth::user();

            if ($user === null) {
                abort(403);
            }

            $invoice = app(CaptureSupplierInvoice::class)->handle(
                [
                    'supplier_id' => (int) $this->supplierId,
                    'supplier_invoice_no' => $this->supplierInvoiceNo,
                    'invoice_date' => $this->invoiceDate,
                    'due_date' => $this->dueDate !== '' ? $this->dueDate : null,
                    'purchase_order_id' => $this->purchaseOrderId !== '' ? (int) $this->purchaseOrderId : null,
                ],
                $lines,
                $user->toAuditActor(),
            );

            $invoice = app(MatchSupplierInvoice::class)->handle((int) $invoice->getKey(), $user->toAuditActor());

            $this->invoiceId = (int) $invoice->getKey();
            $this->notice = __('opes.supplier_invoice_screen.saved_as').' '.$invoice->internal_no;
        } catch (DomainException|ValidationException $e) {
            $this->error = $e instanceof ValidationException
                ? implode(' ', array_map(
                    static fn (array $messages): string => implode(' ', $messages),
                    $e->errors(),
                ))
                : $e->getMessage();
        }
    }

    public function overrideMatch(): void
    {
        $this->act(function (Actor $actor): void {
            app(OverrideMatchException::class)->handle((int) $this->invoiceId, $this->overrideReason, $actor);
            $this->notice = __('opes.supplier_invoice_screen.match_overridden');
        });
    }

    public function approve(): void
    {
        $this->act(function (Actor $actor): void {
            app(ApproveSupplierInvoice::class)->handle(
                (int) $this->invoiceId,
                $actor,
                $this->unmatchedReason !== '' ? $this->unmatchedReason : null,
                $this->waiveReason !== '' ? $this->waiveReason : null,
            );
            $this->notice = __('opes.supplier_invoice_screen.approved');
        });
    }

    public function post(): void
    {
        $this->act(function (Actor $actor): void {
            app(PostSupplierInvoice::class)->handle((int) $this->invoiceId, $actor);
            $this->notice = __('opes.supplier_invoice_screen.posted');
        });
    }

    /**
     * @param  callable(Actor): void  $callback
     */
    private function act(callable $callback): void
    {
        $this->error = null;
        $this->notice = null;

        if ($this->invoiceId === null) {
            return;
        }

        try {
            $user = Auth::user();

            if ($user === null) {
                abort(403);
            }

            $callback($user->toAuditActor());
        } catch (DomainException|ValidationException $e) {
            $this->error = $e instanceof ValidationException
                ? implode(' ', array_map(
                    static fn (array $messages): string => implode(' ', $messages),
                    $e->errors(),
                ))
                : $e->getMessage();
        }
    }

    public function render(): mixed
    {
        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $taxCodes = DB::table('tax_codes')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'rate_bp']);

        $accounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(function ($query): void {
                $query->where('code', 'like', '6%')->orWhere('code', 'like', '2%');
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $invoice = null;
        $invoiceLines = collect();

        if ($this->invoiceId !== null) {
            $invoice = SupplierInvoice::query()->find($this->invoiceId);

            if ($invoice !== null) {
                $invoiceLines = DB::table('supplier_invoice_lines as l')
                    ->leftJoin('tax_codes as t', 't.id', '=', 'l.tax_code_id')
                    ->leftJoin('withholding_rules as w', 'w.id', '=', 'l.withholding_rule_id')
                    ->where('l.supplier_invoice_id', $this->invoiceId)
                    ->orderBy('l.line_no')
                    ->get([
                        'l.line_no', 'l.description', 'l.quantity', 'l.unit_price_ht', 'l.amount_ht',
                        'l.tax_amount', 'l.deductible_tax_amount', 'l.non_deductible_tax_amount',
                        'l.withholding_amount', 'l.withholding_reason', 'l.match_status',
                        'l.match_exception_reason', 'l.price_variance', 'l.quantity_variance', 'l.matched_qty',
                        't.code as tax_code', 'w.name as withholding_rule_name',
                    ]);
            }
        }

        return view('livewire.procurement.supplier-invoices.capture', [
            'suppliers' => $suppliers,
            'taxCodes' => $taxCodes,
            'accounts' => $accounts,
            'invoiceModel' => $invoice,
            'invoiceLines' => $invoiceLines,
        ]);
    }
}
