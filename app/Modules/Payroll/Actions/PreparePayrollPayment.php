<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\PaymentLineStatus;
use App\Modules\Payroll\Domain\PayrollPaymentStatus;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayment;
use App\Modules\Payroll\Models\PayrollPaymentLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Audit\Actor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Prepares the net-pay disbursement of an APPROVED run
 * (docs/specs/05-hr-payroll.md 8.8): one payment header, one line per
 * payroll item still owed, beneficiary coordinates copied (re-encrypted)
 * from the staff record so the export decrypts exactly once.
 *
 * Idempotency is structural: the lines' generated `live_item_key` UNIQUE
 * refuses a second live line per payroll item, so preparing twice - or
 * racing two prepares - cannot double-disburse. No ledger write happens
 * here; that is the export's PostFromEvent('payroll.paid') moment.
 */
final class PreparePayrollPayment
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $payrollRunId,
        string $paymentMethod,
        int $treasuryAccountId,
        string $valueDate,
        Actor $actor,
    ): PayrollPayment {
        Gate::authorize(PayrollPermission::PAY);

        if (! in_array($paymentMethod, ['cash', 'mobile_money', 'bank'], true)) {
            throw ValidationException::withMessages([
                'payment_method' => "Unknown payment method '{$paymentMethod}'.",
            ]);
        }

        return DB::transaction(function () use ($payrollRunId, $paymentMethod, $treasuryAccountId, $valueDate, $actor): PayrollPayment {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($payrollRunId)->lockForUpdate()->firstOrFail();

            if (! in_array($run->status, [RunStatus::Approved, RunStatus::Paid], true)) {
                throw ValidationException::withMessages([
                    'payroll_run_id' => "Only an approved run is disbursed; run #{$run->id} is '{$run->status->value}'.",
                ]);
            }

            /** @var list<PayrollItem> $items */
            $items = PayrollItem::query()
                ->where('payroll_run_id', $run->id)
                ->where('is_cancelled', false)
                ->where('net', '>', 0)
                ->orderBy('id')
                ->get()
                ->all();

            if ($items === []) {
                throw ValidationException::withMessages([
                    'payroll_run_id' => 'The run has no positive net pay to disburse.',
                ]);
            }

            // Items already covered by a live (non-failed) line are excluded;
            // if nothing remains, this run is already fully disbursed.
            $covered = PayrollPaymentLine::query()
                ->whereIn('payroll_item_id', array_map(static fn (PayrollItem $i): int => $i->id, $items))
                ->where('status', '<>', PaymentLineStatus::Failed->value)
                ->pluck('payroll_item_id')
                ->all();

            $due = array_values(array_filter(
                $items,
                static fn (PayrollItem $item): bool => ! in_array($item->id, $covered, true),
            ));

            if ($due === []) {
                throw ValidationException::withMessages([
                    'payroll_run_id' => 'Every payroll item of this run is already covered by a live payment line.',
                ]);
            }

            $total = 0;
            foreach ($due as $item) {
                $total += $item->net;
            }

            $payment = PayrollPayment::query()->create([
                'payroll_run_id' => $run->id,
                'payment_method' => $paymentMethod,
                'treasury_account_id' => $treasuryAccountId,
                'value_date' => $valueDate,
                'total_amount' => $total,
                'status' => PayrollPaymentStatus::Prepared,
                'idempotency_key' => 'ppay|'.$run->id.'|'.sha1($paymentMethod.'|'.implode(',', array_map(
                    static fn (PayrollItem $i): int => $i->id,
                    $due,
                ))),
                'created_by' => (int) $actor->id,
            ]);

            foreach ($due as $item) {
                PayrollPaymentLine::query()->create([
                    'payroll_payment_id' => $payment->id,
                    'payroll_item_id' => $item->id,
                    'staff_member_id' => $item->staff_member_id,
                    'amount' => $item->net,
                    'beneficiary_account' => $this->beneficiaryFor($item->staff_member_id, $paymentMethod),
                    'status' => PaymentLineStatus::Pending,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Payroll',
                auditableType: PayrollPayment::class,
                auditableId: (int) $payment->getKey(),
                after: [
                    'payroll_run_id' => $run->id,
                    'payment_method' => $paymentMethod,
                    'total_amount' => $total,
                    'line_count' => count($due),
                ],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * The plaintext coordinates for the method; the model's `encrypted`
     * cast re-encrypts at rest. HR's column is read via DB::table (00-core
     * 6.2) - the ciphertext is the shared APP_KEY's, so Crypt decrypts it.
     */
    private function beneficiaryFor(int $staffMemberId, string $method): ?string
    {
        if ($method === 'cash') {
            return null;
        }

        $row = DB::table('staff_members')->where('id', $staffMemberId)->first(['bank_account', 'mobile_money_number']);

        $cipher = $method === 'bank' ? $row?->bank_account : $row?->mobile_money_number;

        if ($cipher === null || $cipher === '') {
            return null;
        }

        try {
            return Crypt::decryptString((string) $cipher);
        } catch (DecryptException) {
            return null;
        }
    }
}
