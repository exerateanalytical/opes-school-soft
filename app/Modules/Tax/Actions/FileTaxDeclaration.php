<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.2 - record that the bursar FILED a
 * declaration on impots.cm (the SYSTEM never files anything, §7.4):
 *
 * - `external_reference` (the DGI acknowledgement) is MANDATORY.
 * - The stored `inputs_hash` is RE-VERIFIED against today's ledger; if the
 *   figures changed underneath since generation, filing fails and the
 *   declaration must be regenerated (§7.1).
 * - Form-box gate: a type whose official DGI box mapping (`form_boxes`) is
 *   still unverified cannot be marked filed (§7.1) - except `dsf_annual`,
 *   whose line codes come from ChartOfAccount.dsf_line_code, the §7.5
 *   verified mapping mechanism (and whose filing runs through
 *   RecordDsfFiling's checklist, not here).
 * - Optional settlement posting: when the caller supplies the liability
 *   and counterpart accounts, the payable is recognised through
 *   PostFromEvent (`tax.vat.declared` / `tax.remitted` - the single
 *   posting path; the posting RULE is the school's own configuration).
 */
final class FileTaxDeclaration
{
    public const PERMISSION = Permission::TaxFile->value;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly PostFromEvent $postFromEvent,
        private readonly GenerateTvaDeclaration $tvaGenerator,
        private readonly GenerateWithholdingDeclaration $withholdingGenerator,
    ) {}

    public function handle(
        int $declarationId,
        string $filingChannel,
        string $externalReference,
        Actor $actor,
        ?int $settlementLiabilityAccountId = null,
        ?int $settlementCounterpartAccountId = null,
    ): TaxDeclaration {
        Gate::authorize(self::PERMISSION);

        if (trim($externalReference) === '') {
            throw new DomainException('The DGI acknowledgement (external_reference) is mandatory when filing (03-tax-procurement §7.1).');
        }

        if (! in_array($filingChannel, ['impots_cm', 'paper', 'other'], true)) {
            throw new DomainException('filing_channel must be impots_cm, paper or other.');
        }

        return DB::transaction(function () use ($declarationId, $filingChannel, $externalReference, $actor, $settlementLiabilityAccountId, $settlementCounterpartAccountId): TaxDeclaration {
            /** @var TaxDeclaration $declaration */
            $declaration = TaxDeclaration::query()->lockForUpdate()->findOrFail($declarationId);

            if ($declaration->declaration_type === DeclarationTypeCode::DsfAnnual->value) {
                throw new DomainException('The DSF is filed through RecordDsfFiling, which runs the §7.5 pre-filing checklist.');
            }

            if (! $declaration->status->isFileable()) {
                throw new DomainException(sprintf(
                    'Declaration %s %04d-%02d is %s; only a generated or under-review declaration can be filed.',
                    $declaration->declaration_type,
                    $declaration->period_year,
                    $declaration->period_month,
                    $declaration->status->value,
                ));
            }

            $this->assertFormBoxesMapped($declaration);
            $this->assertInputsUnchanged($declaration);

            $declaration->forceFill([
                'status' => DeclarationStatus::Filed->value,
                'filed_at' => now(),
                'filed_by' => $actor->id,
                'filing_channel' => $filingChannel,
                'external_reference' => $externalReference,
            ])->save();

            // The original a filed amendment supersedes flips to `amended`.
            if ($declaration->amends_declaration_id !== null) {
                TaxDeclaration::query()
                    ->whereKey($declaration->amends_declaration_id)
                    ->lockForUpdate()
                    ->get()
                    ->each(function (TaxDeclaration $original): void {
                        $original->forceFill(['status' => DeclarationStatus::Amended->value])->save();
                    });
            }

            if ($settlementLiabilityAccountId !== null || $settlementCounterpartAccountId !== null) {
                if ($settlementLiabilityAccountId === null || $settlementCounterpartAccountId === null) {
                    throw new DomainException('A settlement posting needs BOTH the liability and the counterpart account.');
                }

                if ($declaration->amount_declared > 0) {
                    $event = $declaration->declaration_type === DeclarationTypeCode::TvaMonthly->value
                        ? 'tax.vat.declared'
                        : 'tax.remitted';

                    // The single posting path (02-accounting §11.1); the
                    // rule mapping this event is the school's own data.
                    $this->postFromEvent->handle(
                        $event,
                        [
                            'declaration' => [
                                'amount' => $declaration->amount_declared,
                                'reference' => $externalReference,
                                'liability_account_id' => $settlementLiabilityAccountId,
                                'counterpart_account_id' => $settlementCounterpartAccountId,
                            ],
                        ],
                        BusinessDate::today(),
                        $actor,
                        $externalReference,
                    );
                }
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: TaxDeclaration::class,
                auditableId: (int) $declaration->getKey(),
                after: [
                    'status' => DeclarationStatus::Filed->value,
                    'filing_channel' => $filingChannel,
                    'external_reference' => $externalReference,
                    'amount_declared' => $declaration->amount_declared,
                ],
                actor: $actor,
            );

            return $declaration->refresh();
        });
    }

    /** §7.1: internal line codes cannot be filed until the form is mapped. */
    private function assertFormBoxesMapped(TaxDeclaration $declaration): void
    {
        $formBoxes = $declaration->type()->first()?->form_boxes;

        if ($formBoxes === null || $formBoxes === []) {
            throw new DomainException(sprintf(
                'Declaration type %s is not yet mapped to the official DGI form (form box codes NEEDS VERIFICATION - 03-tax-procurement §7.1). It can be generated and reviewed, but not marked filed, until the mapping is configured.',
                $declaration->declaration_type,
            ));
        }
    }

    private function assertInputsUnchanged(TaxDeclaration $declaration): void
    {
        $current = match ($declaration->declaration_type) {
            DeclarationTypeCode::TvaMonthly->value => $this->tvaGenerator->currentInputsHash($declaration),
            DeclarationTypeCode::WithholdingMonthly->value => $this->withholdingGenerator->currentInputsHash($declaration),
            default => $declaration->inputs_hash,
        };

        if ($declaration->inputs_hash === null || $current !== $declaration->inputs_hash) {
            throw new DomainException(sprintf(
                'The ledger changed underneath declaration %s %04d-%02d since it was generated (inputs_hash mismatch). Regenerate before filing (03-tax-procurement §7.1).',
                $declaration->declaration_type,
                $declaration->period_year,
                $declaration->period_month,
            ));
        }
    }
}
