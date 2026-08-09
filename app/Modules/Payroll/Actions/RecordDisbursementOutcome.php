<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\PaymentLineStatus;
use App\Modules\Payroll\Domain\PayrollPaymentStatus;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\PayrollPayment;
use App\Modules\Payroll\Models\PayrollPaymentLine;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records what the bank / MoMo processor reported per line
 * (docs/specs/05-hr-payroll.md 8.8): a failed line does NOT fail the
 * payment - it moves the payment to `partially_failed` and, being `failed`,
 * releases its payroll item's live_item_key for re-export.
 */
final class RecordDisbursementOutcome
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, array{status: string, failure_reason?: string|null}>  $outcomes  keyed by line id
     */
    public function handle(int $paymentId, array $outcomes, Actor $actor): PayrollPayment
    {
        Gate::authorize(PayrollPermission::PAY);

        return DB::transaction(function () use ($paymentId, $outcomes, $actor): PayrollPayment {
            /** @var PayrollPayment $payment */
            $payment = PayrollPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, [PayrollPaymentStatus::Exported, PayrollPaymentStatus::PartiallyFailed], true)) {
                throw ValidationException::withMessages([
                    'status' => "Outcomes are recorded against an exported payment; #{$payment->id} is '{$payment->status->value}'.",
                ]);
            }

            foreach ($outcomes as $lineId => $outcome) {
                $status = PaymentLineStatus::tryFrom($outcome['status'] ?? '');

                if (! in_array($status, [PaymentLineStatus::Confirmed, PaymentLineStatus::Failed], true)) {
                    throw ValidationException::withMessages([
                        'outcomes' => "Line {$lineId}: an outcome is 'confirmed' or 'failed'.",
                    ]);
                }

                if ($status === PaymentLineStatus::Failed
                    && trim((string) ($outcome['failure_reason'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'outcomes' => "Line {$lineId}: a failed outcome requires a failure reason.",
                    ]);
                }

                $updated = PayrollPaymentLine::query()
                    ->whereKey($lineId)
                    ->where('payroll_payment_id', $payment->id)
                    ->where('status', PaymentLineStatus::Exported->value)
                    ->update([
                        'status' => $status->value,
                        'failure_reason' => $status === PaymentLineStatus::Failed
                            ? (string) $outcome['failure_reason']
                            : null,
                    ]);

                if ($updated !== 1) {
                    throw ValidationException::withMessages([
                        'outcomes' => "Line {$lineId} is not an exported line of payment #{$payment->id}.",
                    ]);
                }
            }

            $anyFailed = PayrollPaymentLine::query()
                ->where('payroll_payment_id', $payment->id)
                ->where('status', PaymentLineStatus::Failed->value)
                ->exists();

            $allConfirmed = ! PayrollPaymentLine::query()
                ->where('payroll_payment_id', $payment->id)
                ->where('status', '<>', PaymentLineStatus::Confirmed->value)
                ->exists();

            $payment->fill([
                'status' => $anyFailed
                    ? PayrollPaymentStatus::PartiallyFailed
                    : ($allConfirmed ? PayrollPaymentStatus::Confirmed : PayrollPaymentStatus::Exported),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Payroll',
                auditableType: PayrollPayment::class,
                auditableId: (int) $payment->getKey(),
                after: ['status' => $payment->status->value, 'outcomes' => count($outcomes)],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }
}
