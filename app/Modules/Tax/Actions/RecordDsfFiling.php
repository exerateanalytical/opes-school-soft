<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.5 - record that the bursar filed
 * the DSF on impots.cm (the system NEVER files anything, §7.4). Runs the
 * blocking pre-filing checklist:
 *
 *   1. year hard-locked (fiscal year closed AND every period hard_locked)
 *   2. no unbalanced posted entry in the year
 *   3. all 12 TVA declarations filed - when the school is TVA-registered
 *   4. every issued withholding attestation of the year included in a
 *      FILED withholding declaration (reconciled to 447 at generation)
 *   5. inputs_hash re-verified (the mapped balances did not move)
 *   6. external reference (the impots.cm acknowledgement) mandatory
 *
 * On success it stamps `fiscal_years.dsf_*` - the columns 02-accounting
 * owns and this spec defines - which arms the UNCONDITIONAL ReopenFiscalYear
 * block (§11.10): from here on, the remedy for any error is an amending
 * declaration and correcting entries in the open year, never a reopening.
 */
final class RecordDsfFiling
{
    public const PERMISSION = Permission::TaxFile->value;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly GenerateDsf $generator,
    ) {}

    public function handle(int $declarationId, string $externalReference, Actor $actor, string $filingChannel = 'impots_cm'): TaxDeclaration
    {
        Gate::authorize(self::PERMISSION);

        if (trim($externalReference) === '') {
            throw new DomainException('The impots.cm acknowledgement (external_reference) is mandatory when recording the DSF filing (03-tax-procurement §7.5).');
        }

        return DB::transaction(function () use ($declarationId, $externalReference, $filingChannel, $actor): TaxDeclaration {
            /** @var TaxDeclaration $declaration */
            $declaration = TaxDeclaration::query()->lockForUpdate()->findOrFail($declarationId);

            if ($declaration->declaration_type !== DeclarationTypeCode::DsfAnnual->value) {
                throw new DomainException('RecordDsfFiling records DSF filings only; other declarations file through FileTaxDeclaration.');
            }

            if (! $declaration->status->isFileable()) {
                throw new DomainException(sprintf(
                    'The %d DSF is %s; only a generated or under-review DSF can be filed.',
                    $declaration->period_year,
                    $declaration->status->value,
                ));
            }

            /** @var object{id: int|string, code: string, status: string, dsf_filed_at: string|null}|null $fiscalYear */
            $fiscalYear = DB::table('fiscal_years')
                ->where('id', $declaration->fiscal_year_id)
                ->lockForUpdate()
                ->first(['id', 'code', 'status', 'dsf_filed_at']);

            if ($fiscalYear === null) {
                throw new DomainException('The DSF names a fiscal year that no longer exists; the ledger is inconsistent.');
            }

            if ($fiscalYear->dsf_filed_at !== null) {
                throw new DomainException(sprintf('Fiscal year %s already has a filed DSF; corrections go through an amending declaration.', $fiscalYear->code));
            }

            $this->runChecklist($declaration, $fiscalYear);

            // Checklist item 5: the mapped balances did not move since
            // generation (§7.1 inputs_hash discipline).
            if ($declaration->inputs_hash === null
                || $this->generator->currentInputsHash($declaration) !== $declaration->inputs_hash) {
                throw new DomainException(
                    'The ledger changed underneath the DSF since it was generated (inputs_hash mismatch). Regenerate before filing (03-tax-procurement §7.1).'
                );
            }

            $declaration->forceFill([
                'status' => DeclarationStatus::Filed->value,
                'filed_at' => now(),
                'filed_by' => $actor->id,
                'filing_channel' => $filingChannel,
                'external_reference' => $externalReference,
            ])->save();

            // fiscal_years.dsf_* are owned by 02-accounting but SPECIFIED by
            // 03-tax §7.5 for exactly this Action to set; written via
            // DB::table because FiscalYear is Accounting's model (00-core
            // §6.2 - no cross-module model import).
            DB::table('fiscal_years')
                ->where('id', $declaration->fiscal_year_id)
                ->update([
                    'dsf_filed_at' => now(),
                    'dsf_reference' => $externalReference,
                    'dsf_declaration_id' => $declaration->id,
                    'dsf_filed_by' => $actor->id,
                    'updated_at' => now(),
                ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: TaxDeclaration::class,
                auditableId: (int) $declaration->getKey(),
                after: [
                    'status' => DeclarationStatus::Filed->value,
                    'external_reference' => $externalReference,
                    'fiscal_year_id' => $declaration->fiscal_year_id,
                ],
                actor: $actor,
            );

            return $declaration->refresh();
        });
    }

    /**
     * @param  object{id: int|string, code: string, status: string, dsf_filed_at: string|null}  $fiscalYear
     */
    private function runChecklist(TaxDeclaration $declaration, object $fiscalYear): void
    {
        // 1. Year hard-locked: closed AND every period hard_locked.
        if ($fiscalYear->status !== 'closed') {
            throw new DomainException(sprintf(
                'DSF pre-filing checklist: fiscal year %s is %s, not CLOSED. Complete the clôture before filing (03-tax-procurement §7.5).',
                $fiscalYear->code,
                $fiscalYear->status,
            ));
        }

        $unlockedPeriods = DB::table('accounting_periods')
            ->where('fiscal_year_id', $declaration->fiscal_year_id)
            ->where('status', '!=', 'hard_locked')
            ->count();

        if ($unlockedPeriods > 0) {
            throw new DomainException(sprintf(
                'DSF pre-filing checklist: %d accounting period(s) of the year are not hard-locked (03-tax-procurement §7.5).',
                $unlockedPeriods,
            ));
        }

        // 2. No unbalanced posted entry.
        $unbalanced = DB::table('journal_entries')
            ->where('fiscal_year_id', $declaration->fiscal_year_id)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereColumn('total_debit', '!=', 'total_credit')
            ->count();

        if ($unbalanced > 0) {
            throw new DomainException(sprintf(
                'DSF pre-filing checklist: %d unbalanced entry(ies) in the year (03-tax-procurement §7.5).',
                $unbalanced,
            ));
        }

        // 3. All 12 TVA declarations filed - when TVA-registered.
        $identity = FiscalIdentity::current();

        if ($identity !== null && $identity->is_tva_registered) {
            /** @var list<int> $filedMonths */
            $filedMonths = TaxDeclaration::query()
                ->where('declaration_type', DeclarationTypeCode::TvaMonthly->value)
                ->where('period_year', $declaration->period_year)
                ->whereIn('status', [
                    DeclarationStatus::Filed->value,
                    DeclarationStatus::Paid->value,
                    DeclarationStatus::Amended->value,
                ])
                ->pluck('period_month')->map(fn ($m): int => (int) $m)->unique()->values()->all();

            $missing = array_values(array_diff(range(1, 12), $filedMonths));

            if ($missing !== []) {
                throw new DomainException(sprintf(
                    'DSF pre-filing checklist: TVA declarations not filed for month(s) %s of %d (03-tax-procurement §7.5).',
                    implode(', ', $missing),
                    $declaration->period_year,
                ));
            }
        }

        // 4. Every issued attestation of the year sits in a FILED
        //    withholding declaration.
        $unreconciled = WithholdingAttestation::query()
            ->where('period_year', $declaration->period_year)
            ->where('status', AttestationStatus::Issued->value)
            ->where(function ($query): void {
                $query->whereNull('tax_declaration_id')
                    ->orWhereNotIn('tax_declaration_id', TaxDeclaration::query()
                        ->select('id')
                        ->whereIn('status', [
                            DeclarationStatus::Filed->value,
                            DeclarationStatus::Paid->value,
                            DeclarationStatus::Amended->value,
                        ]));
            })
            ->count();

        if ($unreconciled > 0) {
            throw new DomainException(sprintf(
                'DSF pre-filing checklist: %d withholding attestation(s) of %d are not covered by a FILED withholding declaration (03-tax-procurement §7.5).',
                $unreconciled,
                $declaration->period_year,
            ));
        }
    }
}
