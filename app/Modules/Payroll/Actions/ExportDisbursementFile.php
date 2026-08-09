<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\DisbursementFile;
use App\Modules\Payroll\Domain\PaymentLineStatus;
use App\Modules\Payroll\Domain\PayrollPaymentStatus;
use App\Modules\Payroll\Domain\PayrollPermission;
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
 * Renders the disbursement file and lands the payment in the ledger
 * (docs/specs/05-hr-payroll.md 8.8).
 *
 * - Beneficiary coordinates are decrypted HERE and nowhere else.
 * - A line without coordinates does not fail the payment: it is marked
 *   `failed` with a reason, the payment moves to `partially_failed`, and a
 *   retry re-reads the (corrected) staff record - the spec's re-export path.
 * - Each export batch that actually moves money posts ONE
 *   PostFromEvent('payroll.paid') entry for the batch total; the single
 *   posting path (02-accounting) is preserved, and an already-exported line
 *   can never enter a second batch, so the same franc never posts twice.
 */
final class ExportDisbursementFile
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $paymentId, Actor $actor): DisbursementFile
    {
        Gate::authorize(PayrollPermission::PAY);

        return DB::transaction(function () use ($paymentId, $actor): DisbursementFile {
            /** @var PayrollPayment $payment */
            $payment = PayrollPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, [PayrollPaymentStatus::Prepared, PayrollPaymentStatus::PartiallyFailed], true)) {
                throw ValidationException::withMessages([
                    'status' => "Payment #{$payment->id} is '{$payment->status->value}'; only prepared or partially_failed payments export.",
                ]);
            }

            /** @var list<PayrollPaymentLine> $lines */
            $lines = PayrollPaymentLine::query()
                ->where('payroll_payment_id', $payment->id)
                ->whereIn('status', [PaymentLineStatus::Pending->value, PaymentLineStatus::Failed->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'status' => 'Nothing to export: every line of this payment has already been exported.',
                ]);
            }

            $rows = [];
            $exportedIds = [];
            $exportedTotal = 0;
            $failed = 0;

            foreach ($lines as $line) {
                // A retry re-reads the staff record: the fix for a failed
                // line is correcting the coordinates, not editing the line.
                if ($line->status === PaymentLineStatus::Failed) {
                    $line->beneficiary_account = $this->refreshBeneficiary($line->staff_member_id, $payment->payment_method);
                }

                $beneficiary = $line->beneficiary_account;

                if ($payment->payment_method !== 'cash' && ($beneficiary === null || $beneficiary === '')) {
                    $line->fill([
                        'status' => PaymentLineStatus::Failed,
                        'failure_reason' => 'No beneficiary account on the staff record for method '.$payment->payment_method.'.',
                    ])->save();
                    $failed++;

                    continue;
                }

                $staff = DB::table('staff_members')->where('id', $line->staff_member_id)
                    ->first(['staff_no', 'first_name', 'last_name']);

                $rows[] = implode(',', [
                    (string) $line->id,
                    (string) ($staff !== null ? $staff->staff_no : ''),
                    '"'.($staff !== null ? trim($staff->first_name.' '.$staff->last_name) : '').'"',
                    '"'.($beneficiary ?? 'CASH').'"',
                    (string) $line->amount,
                ]);

                $line->fill(['status' => PaymentLineStatus::Exported, 'failure_reason' => null])->save();
                $exportedIds[] = $line->id;
                $exportedTotal += $line->amount;
            }

            $anyFailedOverall = PayrollPaymentLine::query()
                ->where('payroll_payment_id', $payment->id)
                ->where('status', PaymentLineStatus::Failed->value)
                ->exists();

            $payment->fill([
                'status' => $anyFailedOverall ? PayrollPaymentStatus::PartiallyFailed : PayrollPaymentStatus::Exported,
                'exported_by' => $actor->id,
                'exported_at' => now(),
            ]);

            // The ledger moment: net pay leaves staff payable for treasury,
            // through the ONE posting door. Only a batch that moves money posts.
            if ($exportedTotal > 0) {
                /** @var PayrollRun $run */
                $run = PayrollRun::query()->whereKey($payment->payroll_run_id)->firstOrFail();

                $entry = $this->post->handle(
                    PostingEvent::PayrollPaid->value,
                    [
                        'payment' => [
                            'amount' => $exportedTotal,
                            'reference' => 'PAY-'.$payment->id,
                            'method' => $payment->payment_method,
                            'treasury_account_id' => $payment->treasury_account_id,
                            'payroll_month' => $run->payroll_month->toDateString(),
                        ],
                    ],
                    $payment->value_date->toDateString(),
                    $actor,
                    'PAY-'.$payment->id,
                );

                if ($payment->journal_entry_id === null) {
                    $payment->journal_entry_id = (int) $entry->getKey();
                }
            }

            $payment->save();

            $contents = implode("\n", array_merge(
                ['line_id,staff_no,staff_name,beneficiary_account,amount'],
                $rows,
            ))."\n";

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Payroll',
                auditableType: PayrollPayment::class,
                auditableId: (int) $payment->getKey(),
                before: ['status' => PayrollPaymentStatus::Prepared->value],
                after: [
                    'status' => $payment->status->value,
                    'exported_lines' => count($exportedIds),
                    'exported_total' => $exportedTotal,
                    'failed_lines' => $failed,
                ],
                actor: $actor,
            );

            return new DisbursementFile(
                filename: sprintf('disbursement-PAY-%d-%s.csv', $payment->id, $payment->value_date->toDateString()),
                contents: $contents,
                exportedLineCount: count($exportedIds),
                exportedTotal: $exportedTotal,
            );
        });
    }

    private function refreshBeneficiary(int $staffMemberId, string $method): ?string
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
