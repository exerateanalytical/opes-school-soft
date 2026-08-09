<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\AmountInWords;
use App\Modules\Reporting\Domain\DocumentLanguage;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Support\Fiscal\FiscalIdentityGate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-12-13.md D3 / docs/specs/03-tax-procurement.md §9's UI
 * table ("pay -> print advice + attestation") - the payment voucher for an
 * already-recorded SupplierPayment.
 *
 * Same receipt pattern as Fees' PrintReceipt: `SupplierPayment` is frozen
 * from the moment it is PAID (03-tax-procurement §4.7), so the payment row
 * itself is the immutable snapshot, `payment_no` printed is the number
 * RecordSupplierPayment already allocated - NOT a fresh document-series
 * number - and `snapshotId` is the payment's own id.
 */
final class PrintPaymentVoucher
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $supplierPaymentId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::ProcurementPaymentRecord->value);

        FiscalIdentityGate::assertCompleteForMoneyDocuments();

        /** @var SupplierPayment|null $payment */
        $payment = SupplierPayment::query()->find($supplierPaymentId);

        if ($payment === null) {
            throw new DomainException("Supplier payment {$supplierPaymentId} does not exist.");
        }

        /** @var object{name: string, niu: string|null}|null $supplier */
        $supplier = DB::table('suppliers')->where('id', $payment->supplier_id)->first(['name', 'niu']);

        if ($supplier === null) {
            throw new DomainException("Supplier {$payment->supplier_id} does not exist.");
        }

        $treasuryAccount = DB::table('chart_of_accounts')->where('id', $payment->treasury_account_id)->value('name');

        $allocations = DB::table('supplier_payment_allocations as spa')
            ->join('supplier_invoices as si', 'si.id', '=', 'spa.supplier_invoice_id')
            ->where('spa.supplier_payment_id', $supplierPaymentId)
            ->whereNull('spa.reversed_at')
            ->orderBy('spa.id')
            ->get(['si.invoice_no', 'spa.amount']);

        $allocationRows = [];

        foreach ($allocations as $allocation) {
            /** @var object{invoice_no: string, amount: int|string} $allocation */
            $allocationRows[] = [
                'invoice_no' => (string) $allocation->invoice_no,
                'amount' => (int) $allocation->amount,
            ];
        }

        $lang = DocumentLanguage::tryFrom($language ?? '') ?? DocumentLanguage::En;
        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);

        $payload = [
            'school' => $chrome,
            'voucher' => [
                'payment_no' => $payment->payment_no,
                'date' => $payment->payment_date,
                'supplier_name' => $supplier->name,
                'supplier_niu' => $supplier->niu,
                'method' => $payment->payment_method,
                'treasury_account' => is_string($treasuryAccount) ? $treasuryAccount : '',
                'reference' => $payment->reference,
                'gross_amount' => (int) $payment->gross_amount,
                'withholding_amount' => (int) $payment->withholding_amount,
                'fee_amount' => (int) $payment->fee_amount,
                'net_amount' => (int) $payment->net_amount,
                'amount_words' => AmountInWords::render((int) $payment->net_amount, $lang),
                'allocations' => $allocationRows,
            ],
        ];

        return $this->render->handle(
            templateCode: 'PAY-VOUCHER',
            subjectType: 'SupplierPayment',
            subjectId: $supplierPaymentId,
            subjectLabel: 'Payment voucher '.$payment->payment_no.' for '.$supplier->name,
            snapshotId: $supplierPaymentId,
            language: $language,
            data: $payload,
        );
    }
}
