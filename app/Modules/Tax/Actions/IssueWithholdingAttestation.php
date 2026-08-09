<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §6.6 - issue an attestation de retenue
 * à la source, series ATT.
 *
 * Exactly one of supplier_invoice_id / supplier_payment_id is given, per
 * the recognition basis; the source document row is taken FOR UPDATE (§9)
 * so a concurrent void cannot race an issue. Base, rate and amount are
 * SNAPSHOTTED from the caller's already-computed resolution - never
 * recomputed at print time.
 *
 * Issued directly (status `issued`): the school is LEGALLY required to
 * hand this to the supplier - a draft attestation that never leaves is
 * the §6.6 de facto confiscation.
 */
final class IssueWithholdingAttestation
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array{supplier_id: int, supplier_invoice_id?: int|null, supplier_payment_id?: int|null, withholding_rule_id: int, period_month: int, period_year: int, base_amount: int, rate_bp_applied: int, withheld_amount: int}  $input
     */
    public function handle(array $input, Actor $actor): WithholdingAttestation
    {
        Gate::authorize(Permission::LedgerPost->value);

        $invoiceId = $input['supplier_invoice_id'] ?? null;
        $paymentId = $input['supplier_payment_id'] ?? null;

        if (($invoiceId === null) === ($paymentId === null)) {
            throw new DomainException(
                'An attestation names EXACTLY ONE source: a supplier invoice (on_invoice) or a supplier payment (on_payment) (03-tax-procurement 6.6).'
            );
        }

        if ($input['withheld_amount'] <= 0) {
            throw new DomainException('An attestation certifies a positive withheld amount.');
        }

        return DB::transaction(function () use ($input, $invoiceId, $paymentId, $actor): WithholdingAttestation {
            // §9: FOR UPDATE on the source document (cross-module read via
            // DB::table - the documents are Procurement's models).
            if ($invoiceId !== null) {
                $exists = DB::table('supplier_invoices')->where('id', $invoiceId)->lockForUpdate()->exists();

                if (! $exists) {
                    throw new DomainException("Supplier invoice {$invoiceId} does not exist.");
                }
            }

            if ($paymentId !== null) {
                $exists = DB::table('supplier_payments')->where('id', $paymentId)->lockForUpdate()->exists();

                if (! $exists) {
                    throw new DomainException("Supplier payment {$paymentId} does not exist.");
                }
            }

            /** @var WithholdingRule $rule */
            $rule = WithholdingRule::query()->findOrFail($input['withholding_rule_id']);

            if ($rule->confirmed_at === null) {
                throw new DomainException(
                    "Withholding rule {$rule->code} is unconfirmed; it cannot back a tax document (03-tax-procurement 6.2)."
                );
            }

            $number = sprintf(
                'ATT/%d/%06d',
                $input['period_year'],
                $this->sequence->allocate('ATT'),
            );

            $attestation = WithholdingAttestation::query()->create([
                'attestation_no' => $number,
                'supplier_id' => $input['supplier_id'],
                'supplier_invoice_id' => $invoiceId,
                'supplier_payment_id' => $paymentId,
                'withholding_rule_id' => $input['withholding_rule_id'],
                'period_month' => $input['period_month'],
                'period_year' => $input['period_year'],
                'base_amount' => $input['base_amount'],
                'rate_bp_applied' => $input['rate_bp_applied'],
                'withheld_amount' => $input['withheld_amount'],
                'status' => AttestationStatus::Issued,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Tax',
                auditableType: WithholdingAttestation::class,
                auditableId: (int) $attestation->getKey(),
                after: [
                    'attestation_no' => $number,
                    'supplier_id' => $input['supplier_id'],
                    'withheld_amount' => $input['withheld_amount'],
                    'period' => sprintf('%04d-%02d', $input['period_year'], $input['period_month']),
                ],
                actor: $actor,
            );

            return $attestation->refresh();
        });
    }
}
