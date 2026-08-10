<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Payments;

use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §4.7 - one supplier payment's full
 * detail: header, its invoice allocations, and a print-preview of the
 * payment voucher. The voucher itself is NOT reinvented here - it is the
 * existing PrintPaymentVoucher Action / PrintPaymentVoucherController
 * (GET /procurement/payments/{payment}/voucher), which this screen's
 * "Export PDF" button simply links to; the on-screen preview below mirrors
 * that same voucher layout so the user can check it before printing.
 *
 * Read-only - approve / pay / void all stay on the list screen
 * (Payments\Index). Gated on the SAME permission as that list,
 * `procurement.payment_record`.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $paymentId;

    public function mount(int $payment): void
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);

        $this->paymentId = $payment;

        $exists = DB::table('supplier_payments')->where('id', $payment)->exists();

        if (! $exists) {
            abort(404);
        }
    }

    /**
     * @return object{id:int, payment_no:string, supplier_id:int, supplier_name:string, supplier_code:string, payment_date:string, payment_method:string, treasury_account_id:int, treasury_account_name:string, reference:?string, gross_amount:int, withholding_amount:int, fee_amount:int, fee_bearer:string, net_amount:int, status:string, clearing_state:string, recorded_by:int, approved_by:?int, approved_at:?string, paid_by:?int, paid_at:?string, notes:?string}
     */
    private function payment(): object
    {
        /** @var object $payment */
        $payment = DB::table('supplier_payments as p')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'p.treasury_account_id')
            ->where('p.id', $this->paymentId)
            ->firstOrFail([
                'p.id', 'p.payment_no', 'p.supplier_id', 's.name as supplier_name', 's.code as supplier_code',
                'p.payment_date', 'p.payment_method', 'p.treasury_account_id', 'a.name as treasury_account_name',
                'p.reference', 'p.gross_amount', 'p.withholding_amount', 'p.fee_amount', 'p.fee_bearer',
                'p.net_amount', 'p.status', 'p.clearing_state', 'p.recorded_by', 'p.approved_by', 'p.approved_at',
                'p.paid_by', 'p.paid_at', 'p.notes',
            ]);

        return $payment;
    }

    /**
     * @return Collection<int, object>
     */
    private function allocations(): Collection
    {
        return DB::table('supplier_payment_allocations as spa')
            ->join('supplier_invoices as i', 'i.id', '=', 'spa.supplier_invoice_id')
            ->where('spa.supplier_payment_id', $this->paymentId)
            ->whereNull('spa.reversed_at')
            ->orderBy('spa.id')
            ->get(['i.id', 'i.internal_no', 'i.invoice_date', 'spa.amount', 'spa.withholding_amount']);
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return $name === null ? null : (string) $name;
    }

    public function render(): mixed
    {
        $payment = $this->payment();

        return view('livewire.procurement.payments.show', [
            'payment' => $payment,
            'allocations' => $this->allocations(),
            'recordedByName' => $this->userName($payment->recorded_by),
            'approvedByName' => $this->userName($payment->approved_by),
            'paidByName' => $this->userName($payment->paid_by),
        ]);
    }
}
