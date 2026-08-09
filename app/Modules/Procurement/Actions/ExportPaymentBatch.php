<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Procurement\Models\SupplierPaymentBatch;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.7 - group approved (or paid) bank /
 * mobile-money payments into a disbursement file.
 *
 * No specific bank's layout is specified (NEEDS VERIFICATION per bank) -
 * the one format shipped is a generic `csv_v1`: one line per payment with
 * payment_no, supplier, disbursement coordinates (RIB or MoMo number,
 * decrypted at export - this file goes TO the bank), reference and net
 * amount. `file_hash` (SHA-256) fingerprints exactly what was handed
 * over; the content is returned to the caller for download, never written
 * to a world-readable path.
 *
 * A payment joins at most one batch (`batch_id`); cash payments have no
 * disbursement file and are refused.
 */
final class ExportPaymentBatch
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<int>  $paymentIds
     * @return array{batch: SupplierPaymentBatch, content: string}
     */
    public function handle(array $paymentIds, int $bankAccountId, Actor $actor, string $exportFormat = 'csv_v1'): array
    {
        Gate::authorize(SupplierPaymentPermission::APPROVE);

        $paymentIds = array_values(array_unique($paymentIds));

        if ($paymentIds === []) {
            throw ValidationException::withMessages([
                'payments' => 'A batch must contain at least one payment.',
            ]);
        }

        if ($exportFormat !== 'csv_v1') {
            throw ValidationException::withMessages([
                'export_format' => "Unknown export format '{$exportFormat}'; bank-specific layouts are NEEDS VERIFICATION per bank (03-tax-procurement 4.7).",
            ]);
        }

        return DB::transaction(function () use ($paymentIds, $bankAccountId, $actor, $exportFormat): array {
            /** @var list<SupplierPayment> $payments */
            $payments = SupplierPayment::query()
                ->whereIn('id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            if (count($payments) !== count($paymentIds)) {
                throw new DomainException('One or more payments do not exist.');
            }

            $lines = [];
            $total = 0;

            foreach ($payments as $payment) {
                if (! in_array($payment->status, [SupplierPaymentStatus::Approved, SupplierPaymentStatus::Paid], true)) {
                    throw new DomainException(sprintf(
                        'Payment %s is %s; only approved or paid payments export (03-tax-procurement 4.7).',
                        $payment->payment_no,
                        $payment->status->value,
                    ));
                }

                if ($payment->payment_method === 'cash') {
                    throw new DomainException(sprintf(
                        'Payment %s is cash; a disbursement file carries bank and mobile-money payments only.',
                        $payment->payment_no,
                    ));
                }

                if ($payment->batch_id !== null) {
                    throw new DomainException(sprintf(
                        'Payment %s is already in a batch; a payment is disbursed at most once.',
                        $payment->payment_no,
                    ));
                }

                /** @var Supplier $supplier */
                $supplier = Supplier::query()->findOrFail($payment->supplier_id);

                $coordinates = $payment->payment_method === 'bank'
                    ? ($supplier->bank_account_rib ?? '')
                    : ($supplier->mobile_money_number ?? '');

                if ($coordinates === '') {
                    throw new DomainException(sprintf(
                        'Supplier %s has no %s coordinates on file; the batch cannot disburse %s.',
                        $supplier->code,
                        $payment->payment_method === 'bank' ? 'bank (RIB)' : 'mobile-money',
                        $payment->payment_no,
                    ));
                }

                $lines[] = implode(';', [
                    $payment->payment_no,
                    str_replace(';', ',', $supplier->name),
                    $payment->payment_method,
                    str_replace(';', ',', $coordinates),
                    str_replace(';', ',', (string) $payment->reference),
                    (string) $payment->net_amount,
                ]);

                $total += $payment->net_amount;
            }

            $batchNo = sprintf('PB/%s/%06d', Carbon::now()->format('Y'), $this->sequences->allocate('PB'));

            $content = "payment_no;supplier;method;coordinates;reference;net_amount\n"
                .implode("\n", $lines)."\n";

            /** @var SupplierPaymentBatch $batch */
            $batch = SupplierPaymentBatch::query()->create([
                'batch_no' => $batchNo,
                'bank_account_id' => $bankAccountId,
                'export_format' => $exportFormat,
                'payment_count' => count($payments),
                'total_amount' => $total,
                'file_hash' => hash('sha256', $content),
                'exported_at' => now(),
                'exported_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            foreach ($payments as $payment) {
                $payment->forceFill([
                    'batch_id' => $batch->getKey(),
                    'version' => $payment->version + 1,
                ])->save();
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: SupplierPaymentBatch::class,
                auditableId: (int) $batch->getKey(),
                after: [
                    'batch_no' => $batchNo,
                    'payment_count' => count($payments),
                    'total_amount' => $total,
                    'file_hash' => $batch->file_hash,
                ],
                actor: $actor,
            );

            return ['batch' => $batch->refresh(), 'content' => $content];
        });
    }
}
