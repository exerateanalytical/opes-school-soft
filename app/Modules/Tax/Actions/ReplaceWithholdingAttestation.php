<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §6.6 invariant 1 - corrections to an
 * ISSUED attestation are a REPLACEMENT CHAIN, never an in-place edit: a
 * successor is issued with its own ATT number, the original flips to
 * `replaced` and points at it via the UNIQUE `replaced_by_attestation_id`.
 *
 * The successor keeps the original's source document, supplier and rule -
 * a "replacement" naming a different payment would be a new attestation,
 * not a correction - while the certified figures may be corrected.
 */
final class ReplaceWithholdingAttestation
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array{base_amount?: int, rate_bp_applied?: int, withheld_amount?: int, period_month?: int, period_year?: int}  $corrections
     */
    public function handle(int $attestationId, array $corrections, string $reason, Actor $actor): WithholdingAttestation
    {
        Gate::authorize(Permission::LedgerPost->value);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Replacing a tax document requires a substantive reason (03-tax-procurement 6.6).',
            ]);
        }

        return DB::transaction(function () use ($attestationId, $corrections, $reason, $actor): WithholdingAttestation {
            /** @var WithholdingAttestation $original */
            $original = WithholdingAttestation::query()->whereKey($attestationId)->lockForUpdate()->firstOrFail();

            if ($original->status !== AttestationStatus::Issued) {
                throw new DomainException(sprintf(
                    'Attestation %s is %s; only an issued attestation can be replaced.',
                    $original->attestation_no,
                    $original->status->value,
                ));
            }

            if ($original->tax_declaration_id !== null) {
                throw new DomainException(sprintf(
                    'Attestation %s is included in a filed declaration; amend the declaration first (03-tax-procurement 6.6 invariant 3).',
                    $original->attestation_no,
                ));
            }

            $withheld = (int) ($corrections['withheld_amount'] ?? $original->withheld_amount);

            if ($withheld <= 0) {
                throw new DomainException('A replacement attestation still certifies a positive withheld amount; to undo entirely, cancel instead.');
            }

            $periodYear = (int) ($corrections['period_year'] ?? $original->period_year);

            $replacement = WithholdingAttestation::query()->create([
                'attestation_no' => sprintf('ATT/%d/%06d', $periodYear, $this->sequence->allocate('ATT')),
                'supplier_id' => $original->supplier_id,
                'supplier_invoice_id' => $original->supplier_invoice_id,
                'supplier_payment_id' => $original->supplier_payment_id,
                'withholding_rule_id' => $original->withholding_rule_id,
                'period_month' => (int) ($corrections['period_month'] ?? $original->period_month),
                'period_year' => $periodYear,
                'base_amount' => (int) ($corrections['base_amount'] ?? $original->base_amount),
                'rate_bp_applied' => (int) ($corrections['rate_bp_applied'] ?? $original->rate_bp_applied),
                'withheld_amount' => $withheld,
                'status' => AttestationStatus::Issued,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $original->forceFill([
                'status' => AttestationStatus::Replaced,
                'replaced_by_attestation_id' => (int) $replacement->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: WithholdingAttestation::class,
                auditableId: (int) $original->getKey(),
                after: [
                    'status' => 'replaced',
                    'replaced_by' => $replacement->attestation_no,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $replacement->refresh();
        });
    }
}
