<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Payments;

use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Procurement\Actions\ComputeInvoiceSettlement;
use App\Modules\Procurement\Actions\RecordSupplierPayment;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the payment workflow screen:
 * select supplier → open invoices with outstanding → allocate → method →
 * WITHHOLDING PREVIEW → record. The §6.4 preview shows what the state
 * takes before a franc moves; the draft then walks the §11.14 chain
 * (approve, pay) on the worklist.
 */
#[Layout('layouts.app')]
final class Pay extends Component
{
    public ?int $supplierId = null;

    public string $paymentDate = '';

    public string $paymentMethod = 'bank';

    public ?int $treasuryAccountId = null;

    public string $reference = '';

    public int $feeAmount = 0;

    public string $feeBearer = 'school';

    public ?int $feeExpenseAccountId = null;

    /** @var array<int, string> invoice id => allocated amount (raw input) */
    public array $allocations = [];

    public ?string $recordedAs = null;

    public function mount(): void
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);
        $this->paymentDate = BusinessDate::today();
    }

    public function updatedSupplierId(): void
    {
        $this->allocations = [];
        $this->recordedAs = null;
    }

    public function record(): void
    {
        $targets = [];

        foreach ($this->allocations as $invoiceId => $raw) {
            $amount = (int) $raw;

            if ($amount > 0) {
                $targets[] = ['supplier_invoice_id' => (int) $invoiceId, 'amount' => $amount];
            }
        }

        try {
            /** @var \App\Modules\Identity\Models\User $user */
            $user = auth()->user();

            $payment = app(RecordSupplierPayment::class)->handle([
                'supplier_id' => (int) $this->supplierId,
                'payment_method' => $this->paymentMethod,
                'treasury_account_id' => (int) $this->treasuryAccountId,
                'payment_date' => $this->paymentDate,
                'reference' => $this->reference,
                'fee_amount' => $this->feeAmount,
                'fee_bearer' => $this->feeBearer,
                'fee_expense_account_id' => $this->feeExpenseAccountId,
                'allocations' => $targets,
            ], $user->toAuditActor());

            $this->recordedAs = $payment->payment_no;
            $this->allocations = [];
        } catch (ValidationException $exception) {
            $this->addError('payment', implode(' ', array_map(
                static fn (array $messages): string => implode(' ', $messages),
                $exception->errors(),
            )));
        } catch (DomainException $exception) {
            $this->addError('payment', $exception->getMessage());
        }
    }

    /**
     * The §6.4 preview rows: every open invoice of the supplier with its
     * outstanding and the withholding a full settlement would recognise.
     *
     * @return list<object{id: int, internal_no: string, supplier_invoice_no: string, due_date: string, total_ttc: int, outstanding: int, withholding_preview: int}&\stdClass>
     */
    private function openInvoices(): array
    {
        if ($this->supplierId === null) {
            return [];
        }

        $settlement = app(ComputeInvoiceSettlement::class);

        try {
            $recognition = $settlement->recognitionBasis();
        } catch (DomainException) {
            return [];
        }

        /** @var list<SupplierInvoice> $invoices */
        $invoices = SupplierInvoice::query()
            ->where('supplier_id', $this->supplierId)
            ->whereIn('status', ['posted', 'partially_paid'])
            ->orderBy('due_date')
            ->get()
            ->all();

        $rows = [];

        foreach ($invoices as $invoice) {
            $outstanding = $settlement->settleableOf($invoice, $recognition)
                - $settlement->allocatedOf((int) $invoice->getKey());

            if ($outstanding <= 0) {
                continue;
            }

            $preview = 0;

            if ($recognition === WithholdingRecognition::OnPayment) {
                try {
                    $preview = $settlement->withholdingAt($invoice, $this->paymentDate)['total']
                        - $settlement->withheldOf((int) $invoice->getKey());
                    $preview = max(0, $preview);
                } catch (DomainException) {
                    $preview = 0;
                }
            }

            $rows[] = (object) [
                'id' => (int) $invoice->getKey(),
                'internal_no' => $invoice->internal_no,
                'supplier_invoice_no' => $invoice->supplier_invoice_no,
                'due_date' => $invoice->due_date,
                'total_ttc' => $invoice->total_ttc,
                'outstanding' => $outstanding,
                'withholding_preview' => $preview,
            ];
        }

        return $rows;
    }

    public function render(): mixed
    {
        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $treasuryAccounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(function ($query): void {
                $query->where('code', 'like', '5%');
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name_fr']);

        return view('livewire.procurement.payments.pay', [
            'suppliers' => $suppliers,
            'treasuryAccounts' => $treasuryAccounts,
            'openInvoices' => $this->openInvoices(),
            // RecordSupplierPayment validates the submitted method against
            // this same enum, so the select is built from it rather than
            // from a list typed into the Blade.
            'paymentMethods' => array_map(
                static fn (PaymentMethod $m): string => $m->value,
                PaymentMethod::cases(),
            ),
        ]);
    }
}
