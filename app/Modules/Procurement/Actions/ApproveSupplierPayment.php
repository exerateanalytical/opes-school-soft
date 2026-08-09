<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.7 / §11.14 - the four-eyes sign-off
 * between drafting a payment and moving money.
 *
 * Segregation of duties, hard and non-overridable: the recorder cannot
 * approve their own draft, whatever permissions they hold. The approver's
 * identity is stamped so PaySupplierPayment can enforce the NEXT pair
 * (approver cannot pay).
 */
final class ApproveSupplierPayment
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $paymentId, Actor $actor): SupplierPayment
    {
        Gate::authorize(SupplierPaymentPermission::APPROVE);

        return DB::transaction(function () use ($paymentId, $actor): SupplierPayment {
            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SupplierPaymentStatus::Draft) {
                throw new DomainException(sprintf(
                    'Payment %s is %s; only a draft can be approved.',
                    $payment->payment_no,
                    $payment->status->value,
                ));
            }

            if ($payment->recorded_by === $actor->id) {
                throw new DomainException(
                    'The clerk who recorded a payment cannot approve it (03-tax-procurement 11.14 segregation of duties).'
                );
            }

            $payment->forceFill([
                'status' => SupplierPaymentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'version' => $payment->version + 1,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierPayment::class,
                auditableId: (int) $payment->getKey(),
                after: ['status' => 'approved'],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }
}
