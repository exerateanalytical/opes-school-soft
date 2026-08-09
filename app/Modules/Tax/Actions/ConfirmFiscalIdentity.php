<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\LegalForm;
use App\Modules\Tax\Domain\TaxCentreType;
use App\Modules\Tax\Domain\TaxRegime;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §2.2/§2.4 - save AND confirm the fiscal
 * identity in one audited act: the wizard's "I confirm these values match
 * the school's registration documents" checkbox writes
 * fiscal_identity_confirmed_by/at through here.
 *
 * Refuses an INCOMPLETE identity - the columns are nullable so the row can
 * be born empty, and this Action is where completeness becomes mandatory.
 * Re-confirming later (tax centre moved, accreditation renewed) runs the
 * same full validation; only the NIU is frozen (§2.2 inv. 1 - a NIU change
 * goes through CorrectFiscalIdentity with reason + document).
 *
 * NIU format is NEEDS VERIFICATION: validated as length ≤ 14 + alphanumeric
 * only, never blocked on shape (spec §2.1 - do not ship a regex that
 * rejects a real NIU).
 */
final class ConfirmFiscalIdentity
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    /** The §2.4 mandatory field set (fiscal-year end has pinned defaults). */
    private const REQUIRED = [
        'legal_name',
        'legal_form',
        'niu',
        'tax_centre_code',
        'tax_centre_name',
        'tax_centre_type',
        'tax_regime',
        'tax_regime_effective_from',
        'ministry_accreditation_number',
        'ministry_accreditation_authority',
        'ministry_accreditation_date',
    ];

    /** Changes to these emit school.fiscal_identity.changed (§2.1). */
    private const HEADER_FIELDS = ['niu', 'rccm_number', 'ministry_accreditation_number'];

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, Actor $actor): FiscalIdentity
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($attributes, $actor): FiscalIdentity {
            /** @var FiscalIdentity|null $identity */
            $identity = FiscalIdentity::query()->lockForUpdate()->find(FiscalIdentity::SINGLETON_ID);

            $merged = [
                ...($identity !== null ? $identity->only($identity->getFillable()) : []),
                ...$attributes,
            ];

            $this->assertComplete($merged);
            $this->assertValid($merged);

            if ($identity !== null && $identity->isConfirmed()
                && array_key_exists('niu', $attributes)
                && (string) $attributes['niu'] !== (string) $identity->niu) {
                throw new DomainException(
                    'The NIU is immutable once the fiscal identity is confirmed; '
                    .'use CorrectFiscalIdentity with a reason and supporting document (03-tax-procurement §2.2).'
                );
            }

            $before = $identity?->only(array_keys($attributes));
            $headerChanged = $identity === null
                || $this->headerChanged($identity, $attributes);

            $confirmation = [
                'fiscal_identity_confirmed_by' => $actor->id,
                'fiscal_identity_confirmed_at' => now(),
            ];

            if ($identity === null) {
                // The PK is not fillable and not auto-incrementing: assign
                // the singleton id explicitly.
                $identity = new FiscalIdentity([...$attributes, ...$confirmation]);
                $identity->setAttribute('id', FiscalIdentity::SINGLETON_ID);
                $identity->save();
            } else {
                $identity->fill([...$attributes, ...$confirmation])->save();
            }

            $this->audit->handle(
                action: $before === null ? AuditAction::Created : AuditAction::Updated,
                module: 'Tax',
                auditableType: FiscalIdentity::class,
                auditableId: (int) $identity->getKey(),
                before: $before,
                after: $attributes,
                actor: $actor,
            );

            if ($headerChanged) {
                // §2.1: invalidates any cached document header downstream.
                Event::dispatch('school.fiscal_identity.changed', [[
                    'niu' => $identity->niu,
                    'rccm_number' => $identity->rccm_number,
                    'ministry_accreditation_number' => $identity->ministry_accreditation_number,
                ]]);
            }

            return $identity->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function assertComplete(array $merged): void
    {
        $missing = [];

        foreach (self::REQUIRED as $field) {
            $value = $merged[$field] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'The fiscal identity cannot be confirmed while incomplete; missing: %s (03-tax-procurement §2.4).',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function assertValid(array $merged): void
    {
        $legalForm = $this->enumFrom(LegalForm::class, $merged['legal_form'], 'legal_form');
        $regime = $this->enumFrom(TaxRegime::class, $merged['tax_regime'], 'tax_regime');
        $this->enumFrom(TaxCentreType::class, $merged['tax_centre_type'], 'tax_centre_type');

        $niu = (string) $merged['niu'];

        if (strlen($niu) > 14 || preg_match('/^[A-Za-z0-9]+$/', $niu) !== 1) {
            // Length + alphanumeric is the ONLY hard check; the full NIU
            // format spec is NEEDS VERIFICATION (spec §2.1).
            throw new DomainException('The NIU must be at most 14 alphanumeric characters.');
        }

        if ($legalForm->requiresRccm()) {
            $rccm = $merged['rccm_number'] ?? null;

            if (! is_string($rccm) || trim($rccm) === '') {
                throw new DomainException(sprintf(
                    'Legal form %s is a commercial form: the RCCM number is mandatory (03-tax-procurement §2.1).',
                    $legalForm->value,
                ));
            }
        }

        if ((bool) ($merged['is_tva_registered'] ?? false)) {
            // §2.2 inv. 2 - whether régime simplifié may register is NEEDS
            // VERIFICATION; until verified only réel is permitted.
            if ($regime !== TaxRegime::Reel) {
                throw new DomainException(
                    'TVA registration requires the régime réel (03-tax-procurement §2.2 invariant 2; '
                    .'whether the régime simplifié may register is unverified).'
                );
            }

            if (($merged['tva_registered_from'] ?? null) === null) {
                throw new DomainException('tva_registered_from is required when the school is TVA-registered.');
            }
        }

        // §2.3: the exercice is pinned to 31/12; the DB CHECK backs this up.
        if ((int) ($merged['fiscal_year_end_month'] ?? 12) !== 12
            || (int) ($merged['fiscal_year_end_day'] ?? 31) !== 31) {
            throw new DomainException(
                'OHADA fixes the exercice at 1 January - 31 December; the fiscal year end is not configurable (§2.3).'
            );
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enumFrom(string $enum, mixed $value, string $field): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new DomainException(sprintf('Unknown %s value.', $field));
        }

        return $case;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function headerChanged(FiscalIdentity $identity, array $attributes): bool
    {
        foreach (self::HEADER_FIELDS as $field) {
            if (array_key_exists($field, $attributes)
                && (string) ($attributes[$field] ?? '') !== (string) ($identity->getAttribute($field) ?? '')) {
                return true;
            }
        }

        return false;
    }
}
