<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Actions\ConfirmFiscalIdentity;
use App\Modules\Tax\Actions\CorrectFiscalIdentity;
use App\Modules\Tax\Domain\LegalForm;
use App\Modules\Tax\Domain\TaxCentreType;
use App\Modules\Tax\Domain\TaxRegime;
use App\Modules\Tax\Models\FiscalIdentity as FiscalIdentityModel;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §2.4 - the fiscal identity screen (the
 * first-run wizard's blocking step, revisitable at /settings/fiscal-identity
 * once Agent F5 wires the route). All §2.1 fields grouped Legal identity ·
 * Tax identity · Accreditation · Fiscal year, with inline legal notes -
 * the bursar entering them is not a tax specialist.
 *
 * The screen holds DRAFT state in the component; nothing persists until
 * the operator ticks the §2.4 confirmation checkbox and saves, which runs
 * ConfirmFiscalIdentity - the Action owns every rule (completeness, NIU
 * shape, TVA-requires-réel, RCCM-for-commercial-forms, NIU freeze).
 * Domain refusals surface verbatim in a banner; they are written for
 * operators.
 */
#[Layout('layouts.app')]
final class FiscalIdentity extends Component
{
    public string $legalName = '';

    public string $legalForm = '';

    public string $niu = '';

    public string $niuIssuedOn = '';

    public string $rccmNumber = '';

    public string $rccmRegistry = '';

    public string $rccmRegisteredOn = '';

    public string $taxCentreCode = '';

    public string $taxCentreName = '';

    public string $taxCentreType = '';

    public string $taxRegime = '';

    public string $taxRegimeEffectiveFrom = '';

    public bool $isTvaRegistered = false;

    public string $tvaRegisteredFrom = '';

    public string $ministryAccreditationNumber = '';

    public string $ministryAccreditationAuthority = '';

    public string $ministryAccreditationDate = '';

    public string $ministryAccreditationExpiresOn = '';

    public bool $confirmChecked = false;

    public string $errorMessage = '';

    public string $successMessage = '';

    // ── Correction form (03-tax-procurement §2.2 invariant 1) ──────────
    public bool $showCorrectionForm = false;

    public string $correctionNiu = '';

    public string $correctionReason = '';

    public string $correctionSupportingDocumentReference = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $identity = FiscalIdentityModel::current();

        if ($identity === null) {
            return;
        }

        $this->legalName = (string) $identity->legal_name;
        $this->legalForm = $identity->legal_form->value ?? '';
        $this->niu = (string) $identity->niu;
        $this->niuIssuedOn = $identity->niu_issued_on?->toDateString() ?? '';
        $this->rccmNumber = (string) $identity->rccm_number;
        $this->rccmRegistry = (string) $identity->rccm_registry;
        $this->rccmRegisteredOn = $identity->rccm_registered_on?->toDateString() ?? '';
        $this->taxCentreCode = (string) $identity->tax_centre_code;
        $this->taxCentreName = (string) $identity->tax_centre_name;
        $this->taxCentreType = $identity->tax_centre_type->value ?? '';
        $this->taxRegime = $identity->tax_regime->value ?? '';
        $this->taxRegimeEffectiveFrom = $identity->tax_regime_effective_from?->toDateString() ?? '';
        $this->isTvaRegistered = $identity->is_tva_registered;
        $this->tvaRegisteredFrom = $identity->tva_registered_from?->toDateString() ?? '';
        $this->ministryAccreditationNumber = (string) $identity->ministry_accreditation_number;
        $this->ministryAccreditationAuthority = (string) $identity->ministry_accreditation_authority;
        $this->ministryAccreditationDate = $identity->ministry_accreditation_date?->toDateString() ?? '';
        $this->ministryAccreditationExpiresOn = $identity->ministry_accreditation_expires_on?->toDateString() ?? '';
    }

    public function save(ConfirmFiscalIdentity $confirm): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        if (! $this->confirmChecked) {
            $this->errorMessage = 'Tick the confirmation box: "I confirm these values match the school\'s registration documents".';

            return;
        }

        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        try {
            $confirm->handle([
                'legal_name' => $this->legalName,
                'legal_form' => $this->legalForm,
                'niu' => $this->niu,
                'niu_issued_on' => $this->niuIssuedOn !== '' ? $this->niuIssuedOn : null,
                'rccm_number' => $this->rccmNumber !== '' ? $this->rccmNumber : null,
                'rccm_registry' => $this->rccmRegistry !== '' ? $this->rccmRegistry : null,
                'rccm_registered_on' => $this->rccmRegisteredOn !== '' ? $this->rccmRegisteredOn : null,
                'tax_centre_code' => $this->taxCentreCode,
                'tax_centre_name' => $this->taxCentreName,
                'tax_centre_type' => $this->taxCentreType,
                'tax_regime' => $this->taxRegime,
                'tax_regime_effective_from' => $this->taxRegimeEffectiveFrom !== '' ? $this->taxRegimeEffectiveFrom : null,
                'is_tva_registered' => $this->isTvaRegistered,
                'tva_registered_from' => $this->tvaRegisteredFrom !== '' ? $this->tvaRegisteredFrom : null,
                'ministry_accreditation_number' => $this->ministryAccreditationNumber,
                'ministry_accreditation_authority' => $this->ministryAccreditationAuthority,
                'ministry_accreditation_date' => $this->ministryAccreditationDate !== '' ? $this->ministryAccreditationDate : null,
                'ministry_accreditation_expires_on' => $this->ministryAccreditationExpiresOn !== '' ? $this->ministryAccreditationExpiresOn : null,
            ], new Actor((int) $user->getAuthIdentifier(), (string) $user->getAttribute('name')));

            $this->successMessage = 'Fiscal identity confirmed.';
            $this->confirmChecked = false;
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function toggleCorrectionForm(): void
    {
        Gate::authorize(CorrectFiscalIdentity::PERMISSION);

        $this->showCorrectionForm = ! $this->showCorrectionForm;

        if ($this->showCorrectionForm) {
            $this->correctionNiu = (string) FiscalIdentityModel::current()?->niu;
        }
    }

    public function correct(CorrectFiscalIdentity $correct): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        try {
            $correct->handle(
                ['niu' => $this->correctionNiu],
                $this->correctionReason,
                $this->correctionSupportingDocumentReference,
                new Actor((int) $user->getAuthIdentifier(), (string) $user->getAttribute('name')),
            );

            $this->successMessage = 'Fiscal identity corrected.';
            $this->reset(['showCorrectionForm', 'correctionNiu', 'correctionReason', 'correctionSupportingDocumentReference']);
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render(): mixed
    {
        return view('livewire.tax.fiscal-identity', [
            'identity' => FiscalIdentityModel::current(),
            'legalForms' => LegalForm::cases(),
            'taxRegimes' => TaxRegime::cases(),
            'taxCentreTypes' => TaxCentreType::cases(),
        ]);
    }
}
