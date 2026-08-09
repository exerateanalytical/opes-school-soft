<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §2.2 invariant 1 - the ONLY path that
 * may change the NIU (or any other frozen identity field) after
 * confirmation. Permission-gated separately from routine configuration,
 * and it requires a REASON and a SUPPORTING DOCUMENT reference, both stored
 * on the audit entry: a NIU typo silently propagates onto every printed
 * invoice and filed declaration, so correcting one is a recorded act, not
 * an edit.
 *
 * Permission string 'fiscal_identity.correct' - registered as a Permission
 * enum case + role mapping by Agent F5's wiring pass (phase-05 plan §5);
 * spatie's gate denies it until then unless a test creates it.
 */
final class CorrectFiscalIdentity
{
    public const PERMISSION = 'fiscal_identity.correct';

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        array $attributes,
        string $reason,
        string $supportingDocumentReference,
        Actor $actor,
    ): FiscalIdentity {
        Gate::authorize(self::PERMISSION);

        if (trim($reason) === '') {
            throw new DomainException('A fiscal-identity correction requires a reason (03-tax-procurement §2.2).');
        }

        if (trim($supportingDocumentReference) === '') {
            throw new DomainException(
                'A fiscal-identity correction requires a supporting document reference (03-tax-procurement §2.2).'
            );
        }

        return DB::transaction(function () use ($attributes, $reason, $supportingDocumentReference, $actor): FiscalIdentity {
            /** @var FiscalIdentity|null $identity */
            $identity = FiscalIdentity::query()->lockForUpdate()->find(FiscalIdentity::SINGLETON_ID);

            if ($identity === null) {
                throw new DomainException(
                    'There is no fiscal identity to correct; confirm one first (ConfirmFiscalIdentity).'
                );
            }

            $before = $identity->only(array_keys($attributes));

            FiscalIdentity::withNiuCorrection(function () use ($identity, $attributes): void {
                $identity->fill($attributes)->save();
            });

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: FiscalIdentity::class,
                auditableId: (int) $identity->getKey(),
                before: $before,
                after: [
                    ...$attributes,
                    'correction_reason' => $reason,
                    'supporting_document_reference' => $supportingDocumentReference,
                ],
                actor: $actor,
            );

            // §2.1: identity-header changes invalidate cached document
            // headers downstream. A correction always touches the header
            // fields' trust, so emit unconditionally.
            Event::dispatch('school.fiscal_identity.changed', [[
                'niu' => $identity->niu,
                'rccm_number' => $identity->rccm_number,
                'ministry_accreditation_number' => $identity->ministry_accreditation_number,
            ]]);

            return $identity->refresh();
        });
    }
}
