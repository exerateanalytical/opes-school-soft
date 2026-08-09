<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §6.6 invariant 2 - cancel an issued
 * attestation, with a mandatory reason. Called by the payment-void and
 * invoice-cancel cascades: an attestation for a payment that never
 * happened is a FALSE TAX DOCUMENT and must fall in the same transaction.
 *
 * An attestation already included in a FILED declaration cannot simply be
 * cancelled - the declaration must be amended (F5's AmendTaxDeclaration);
 * refusing here keeps §6.6 invariant 3's reconciliation intact.
 */
final class CancelWithholdingAttestation
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $attestationId, string $reason, Actor $actor): WithholdingAttestation
    {
        Gate::authorize(Permission::LedgerPost->value);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Cancelling a tax document requires a substantive reason (03-tax-procurement 6.6).',
            ]);
        }

        return DB::transaction(function () use ($attestationId, $reason, $actor): WithholdingAttestation {
            /** @var WithholdingAttestation $attestation */
            $attestation = WithholdingAttestation::query()->whereKey($attestationId)->lockForUpdate()->firstOrFail();

            if ($attestation->status !== AttestationStatus::Issued) {
                throw new DomainException(sprintf(
                    'Attestation %s is %s; only an issued attestation can be cancelled.',
                    $attestation->attestation_no,
                    $attestation->status->value,
                ));
            }

            if ($attestation->tax_declaration_id !== null) {
                throw new DomainException(sprintf(
                    'Attestation %s is included in a filed declaration; amend the declaration instead of cancelling the attestation (03-tax-procurement 6.6 invariant 3).',
                    $attestation->attestation_no,
                ));
            }

            $attestation->forceFill([
                'status' => AttestationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: WithholdingAttestation::class,
                auditableId: (int) $attestation->getKey(),
                after: ['status' => 'cancelled', 'reason' => $reason],
                actor: $actor,
            );

            return $attestation->refresh();
        });
    }
}
