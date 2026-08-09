<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/04-fees.md §14 / 00-core §14 - a parent lost the paper. The
 * ORIGINAL issuance row is untouched; a new row with the next copy_no is
 * appended (printed as DUPLICATA, carrying the same legal receipt_no), the
 * reason is recorded, and the audit chain shows every copy that ever left
 * the office. A voided payment's receipt can never be reissued - that
 * paper would collect money the ledger has already reversed (§11.5 step 7).
 */
final class ReissueReceipt
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $paymentId, string $reason, Actor $actor): Receipt
    {
        Gate::authorize(Permission::FeeCollect->value);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reissue requires a reason - every printed copy is accountable (00-core §14).',
            ]);
        }

        return DB::transaction(function () use ($paymentId, $reason, $actor): Receipt {
            $payment = Payment::lockForAllocation($paymentId);

            if ($payment->isVoided()) {
                throw new DomainException(sprintf(
                    'Receipt %s belongs to a voided payment and cannot be reissued (04-fees §11.5).',
                    $payment->receipt_no,
                ));
            }

            $lastCopy = (int) Receipt::query()
                ->where('payment_id', $payment->getKey())
                ->max('copy_no');

            /** @var Receipt $receipt */
            $receipt = Receipt::query()->create([
                'payment_id' => $payment->getKey(),
                'receipt_no' => $payment->receipt_no,
                'copy_no' => $lastCopy + 1,
                'reissue_reason' => $reason,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: Receipt::class,
                auditableId: (int) $receipt->getKey(),
                after: [
                    'payment_id' => (int) $payment->getKey(),
                    'receipt_no' => $payment->receipt_no,
                    'copy_no' => $lastCopy + 1,
                    'reissue_reason' => $reason,
                ],
                actor: $actor,
            );

            return $receipt;
        });
    }
}
