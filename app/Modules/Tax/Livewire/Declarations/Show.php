<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire\Declarations;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Actions\AmendTaxDeclaration;
use App\Modules\Tax\Actions\FileTaxDeclaration;
use App\Modules\Tax\Actions\IssueWithholdingAttestation;
use App\Modules\Tax\Actions\RecordDsfFiling;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - one declaration: header, lines,
 * the §7.3 per-supplier annex, the inputs_hash and the amendment chain.
 * Carries the §7.1 "not yet mapped to the official form" banner while the
 * type's DGI box mapping is unverified (dsf_annual excepted - its line
 * codes come from ChartOfAccount.dsf_line_code, the verified mechanism).
 *
 * Filing and amending are gated `tax.file` / `tax.declare` respectively -
 * DIFFERENT rights on purpose (SoD between generating and filing) - each
 * Action re-checks its own permission; the toggle-forms below only avoid
 * rendering a control the current user cannot use.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $declarationId;

    public bool $showFileForm = false;

    public string $fileChannel = 'impots_cm';

    public string $fileExternalReference = '';

    public bool $showAmendForm = false;

    public string $amendReason = '';

    public bool $showAttestationForm = false;

    public string $attSupplierId = '';

    public string $attSourceType = 'invoice';

    public string $attSourceId = '';

    public string $attWithholdingRuleId = '';

    public string $attPeriodMonth = '';

    public string $attPeriodYear = '';

    public string $attBaseAmount = '';

    public string $attRateBpApplied = '';

    public string $attWithheldAmount = '';

    public function mount(int $declaration): void
    {
        Gate::authorize(Permission::TaxDeclare->value);

        $this->declarationId = $declaration;
    }

    public function toggleFileForm(): void
    {
        $this->showFileForm = ! $this->showFileForm;
    }

    public function file(FileTaxDeclaration $fileDeclaration, RecordDsfFiling $recordDsf): void
    {
        Gate::authorize(Permission::TaxFile->value);

        $declaration = TaxDeclaration::query()->findOrFail($this->declarationId);

        try {
            if ($declaration->declaration_type === DeclarationTypeCode::DsfAnnual->value) {
                $recordDsf->handle($this->declarationId, $this->fileExternalReference, $this->actor(), $this->fileChannel);
            } else {
                $fileDeclaration->handle($this->declarationId, $this->fileChannel, $this->fileExternalReference, $this->actor());
            }
        } catch (DomainException $exception) {
            $this->addError('fileExternalReference', $exception->getMessage());

            return;
        }

        $this->reset(['showFileForm', 'fileExternalReference']);
        $this->fileChannel = 'impots_cm';
        session()->flash('status', 'Declaration filed.');
    }

    public function toggleAmendForm(): void
    {
        $this->showAmendForm = ! $this->showAmendForm;
    }

    public function amend(AmendTaxDeclaration $amend): void
    {
        Gate::authorize(Permission::TaxDeclare->value);

        try {
            $amendment = $amend->handle($this->declarationId, $this->amendReason, $this->actor());
        } catch (DomainException $exception) {
            $this->addError('amendReason', $exception->getMessage());

            return;
        }

        $this->reset(['showAmendForm', 'amendReason']);
        session()->flash('status', 'Amendment generated as declaration #'.$amendment->id.'.');

        $this->redirectRoute('tax.declarations.show', ['declaration' => $amendment->id]);
    }

    public function toggleAttestationForm(): void
    {
        $this->showAttestationForm = ! $this->showAttestationForm;
    }

    public function issueAttestation(IssueWithholdingAttestation $issue): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        try {
            $issue->handle([
                'supplier_id' => (int) $this->attSupplierId,
                'supplier_invoice_id' => $this->attSourceType === 'invoice' ? (int) $this->attSourceId : null,
                'supplier_payment_id' => $this->attSourceType === 'payment' ? (int) $this->attSourceId : null,
                'withholding_rule_id' => (int) $this->attWithholdingRuleId,
                'period_month' => (int) $this->attPeriodMonth,
                'period_year' => (int) $this->attPeriodYear,
                'base_amount' => (int) $this->attBaseAmount,
                'rate_bp_applied' => (int) $this->attRateBpApplied,
                'withheld_amount' => (int) $this->attWithheldAmount,
            ], $this->actor());
        } catch (DomainException $exception) {
            $this->addError('attWithheldAmount', $exception->getMessage());

            return;
        }

        $this->reset([
            'showAttestationForm', 'attSupplierId', 'attSourceType', 'attSourceId', 'attWithholdingRuleId',
            'attPeriodMonth', 'attPeriodYear', 'attBaseAmount', 'attRateBpApplied', 'attWithheldAmount',
        ]);
        session()->flash('status', 'Withholding attestation issued.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = Auth::user();

        return $user->toAuditActor();
    }

    public function render(): mixed
    {
        /** @var TaxDeclaration $declaration */
        $declaration = TaxDeclaration::query()
            ->with(['lines', 'type'])
            ->findOrFail($this->declarationId);

        $formBoxes = $declaration->type()->first()?->form_boxes;

        $unmappedForm = $declaration->declaration_type !== DeclarationTypeCode::DsfAnnual->value
            && ($formBoxes === null || $formBoxes === []);

        $isWithholding = $declaration->declaration_type === DeclarationTypeCode::WithholdingMonthly->value;

        // The attestations issued for this declaration's period. The print
        // route existed and worked but nothing anywhere linked it, so an
        // issued attestation could only be reached by typing its URL.
        // Period-matched, not FK-matched: tax_declaration_id is only set
        // when the declaration is generated FROM the attestations, and an
        // attestation issued afterwards would silently vanish from the list.
        $attestations = $isWithholding
            ? \Illuminate\Support\Facades\DB::table('withholding_attestations as wa')
                ->join('suppliers as s', 's.id', '=', 'wa.supplier_id')
                ->where('wa.period_month', $declaration->period_month)
                ->where('wa.period_year', $declaration->period_year)
                ->orderBy('wa.id')
                ->get(['wa.id', 'wa.attestation_no', 'wa.status', 'wa.withheld_amount', 's.name as supplier_name'])
            : collect();

        return view('livewire.tax.declarations.show', [
            'declaration' => $declaration,
            'unmappedForm' => $unmappedForm,
            'isDsf' => $declaration->declaration_type === DeclarationTypeCode::DsfAnnual->value,
            'isWithholding' => $isWithholding,
            'attestations' => $attestations,
        ]);
    }
}
