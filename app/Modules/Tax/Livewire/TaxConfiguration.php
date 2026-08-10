<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Actions\ConfigureTaxCode;
use App\Modules\Tax\Actions\ConfigureTaxSettings;
use App\Modules\Tax\Actions\ConfigureWithholdingProfile;
use App\Modules\Tax\Actions\ConfigureWithholdingRule;
use App\Modules\Tax\Models\FiscalIdentity as FiscalIdentityModel;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\TaxObligation;
use App\Modules\Tax\Models\TaxSettings;
use App\Modules\Tax\Models\VatProrata;
use App\Modules\Tax\Models\WithholdingProfile;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the tax configuration cockpit at
 * /settings/tax (route wired by Agent F5). Tabs: tax codes · withholding
 * rules/profiles · prorata · obligations, each carrying its
 * "not configured - blocks use" badge so the empty-and-blocking state
 * (00-core §16) is VISIBLE, not a surprise at the first refusal.
 *
 * Rows are configured through the audited Actions (ConfigureTaxCode,
 * ConfigureWithholdingRule, ConfigureWithholdingProfile,
 * ConfigureTaxSettings) via toggle-forms on this screen; ComputeVatProrata
 * remains out of scope for this pass.
 */
#[Layout('layouts.app')]
final class TaxConfiguration extends Component
{
    #[Url]
    public string $tab = 'tax-codes';

    // ── Tax code form ───────────────────────────────────────────────────
    public bool $showTaxCodeForm = false;

    public ?int $taxCodeId = null;

    public string $tcCode = '';

    public string $tcName = '';

    public string $tcNameFr = '';

    public string $tcTaxType = 'tva';

    public string $tcRateBp = '0';

    public string $tcDirection = 'output';

    public string $tcEffectiveFrom = '';

    public string $tcEffectiveTo = '';

    public bool $tcIsExempt = false;

    public bool $tcIsZeroRated = false;

    public string $tcExemptionLegalRef = '';

    public bool $tcIsActive = true;

    // ── Withholding rule form ───────────────────────────────────────────
    public bool $showWithholdingRuleForm = false;

    public ?int $withholdingRuleId = null;

    public string $wrCode = '';

    public string $wrName = '';

    public string $wrNameFr = '';

    public string $wrWithholdingType = 'air';

    public string $wrRateBp = '0';

    public string $wrBase = '';

    public string $wrAppliesTo = 'both';

    public string $wrMinimumBase = '0';

    public string $wrPriority = '0';

    public string $wrLegalRef = '';

    public string $wrEffectiveFrom = '';

    public string $wrEffectiveTo = '';

    public bool $wrIsActive = true;

    // ── Withholding profile form ────────────────────────────────────────
    public bool $showWithholdingProfileForm = false;

    public ?int $withholdingProfileId = null;

    public string $wpCode = '';

    public string $wpName = '';

    public string $wpNameFr = '';

    /** Comma-separated "rule_id:sequence" pairs, e.g. "1:1,2:2". */
    public string $wpRulesCsv = '';

    // ── Tax settings form ───────────────────────────────────────────────
    public bool $showTaxSettingsForm = false;

    public string $tsWithholdingRecognition = '';

    public string $tsProrataRounding = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['tax-codes', 'withholding', 'prorata', 'obligations'], true)) {
            $this->tab = $tab;
        }
    }

    public function toggleTaxCodeForm(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $this->showTaxCodeForm = ! $this->showTaxCodeForm;
    }

    public function editTaxCode(int $taxCodeId): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        /** @var TaxCode $taxCode */
        $taxCode = TaxCode::query()->findOrFail($taxCodeId);

        $this->taxCodeId = $taxCode->id;
        $this->tcCode = $taxCode->code;
        $this->tcName = $taxCode->name;
        $this->tcNameFr = $taxCode->name_fr;
        $this->tcTaxType = $taxCode->tax_type->value;
        $this->tcRateBp = (string) $taxCode->rate_bp;
        $this->tcDirection = $taxCode->direction;
        $this->tcEffectiveFrom = $taxCode->effective_from->toDateString();
        $this->tcEffectiveTo = $taxCode->effective_to?->toDateString() ?? '';
        $this->tcIsExempt = $taxCode->is_exempt;
        $this->tcIsZeroRated = $taxCode->is_zero_rated;
        $this->tcExemptionLegalRef = (string) $taxCode->exemption_legal_ref;
        $this->tcIsActive = $taxCode->is_active;
        $this->showTaxCodeForm = true;
    }

    public function saveTaxCode(ConfigureTaxCode $action): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        try {
            $action->handle($this->taxCodeId, [
                'code' => $this->tcCode,
                'name' => $this->tcName,
                'name_fr' => $this->tcNameFr,
                'tax_type' => $this->tcTaxType,
                'rate_bp' => (int) $this->tcRateBp,
                'direction' => $this->tcDirection,
                'effective_from' => $this->tcEffectiveFrom,
                'effective_to' => $this->tcEffectiveTo !== '' ? $this->tcEffectiveTo : null,
                'is_exempt' => $this->tcIsExempt,
                'is_zero_rated' => $this->tcIsZeroRated,
                'exemption_legal_ref' => $this->tcExemptionLegalRef !== '' ? $this->tcExemptionLegalRef : null,
                'is_active' => $this->tcIsActive,
            ], $this->actor());
        } catch (DomainException $exception) {
            $this->addError('tcCode', $exception->getMessage());

            return;
        }

        $this->reset([
            'showTaxCodeForm', 'taxCodeId', 'tcCode', 'tcName', 'tcNameFr', 'tcTaxType', 'tcRateBp',
            'tcDirection', 'tcEffectiveFrom', 'tcEffectiveTo', 'tcIsExempt', 'tcIsZeroRated',
            'tcExemptionLegalRef', 'tcIsActive',
        ]);
        session()->flash('status', 'Tax code saved.');
    }

    public function toggleWithholdingRuleForm(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $this->showWithholdingRuleForm = ! $this->showWithholdingRuleForm;
    }

    public function editWithholdingRule(int $withholdingRuleId): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        /** @var WithholdingRule $rule */
        $rule = WithholdingRule::query()->findOrFail($withholdingRuleId);

        $this->withholdingRuleId = $rule->id;
        $this->wrCode = $rule->code;
        $this->wrName = $rule->name;
        $this->wrNameFr = $rule->name_fr;
        $this->wrWithholdingType = $rule->withholding_type->value;
        $this->wrRateBp = (string) $rule->rate_bp;
        $this->wrBase = $rule->base?->value ?? '';
        $this->wrAppliesTo = $rule->applies_to;
        $this->wrMinimumBase = (string) $rule->minimum_base;
        $this->wrPriority = (string) $rule->priority;
        $this->wrLegalRef = (string) $rule->legal_ref;
        $this->wrEffectiveFrom = $rule->effective_from->toDateString();
        $this->wrEffectiveTo = $rule->effective_to?->toDateString() ?? '';
        $this->wrIsActive = $rule->is_active;
        $this->showWithholdingRuleForm = true;
    }

    public function saveWithholdingRule(ConfigureWithholdingRule $action): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        try {
            $action->handle($this->withholdingRuleId, [
                'code' => $this->wrCode,
                'name' => $this->wrName,
                'name_fr' => $this->wrNameFr,
                'withholding_type' => $this->wrWithholdingType,
                'rate_bp' => (int) $this->wrRateBp,
                'base' => $this->wrBase !== '' ? $this->wrBase : null,
                'applies_to' => $this->wrAppliesTo,
                'minimum_base' => (int) $this->wrMinimumBase,
                'priority' => (int) $this->wrPriority,
                'legal_ref' => $this->wrLegalRef !== '' ? $this->wrLegalRef : null,
                'effective_from' => $this->wrEffectiveFrom,
                'effective_to' => $this->wrEffectiveTo !== '' ? $this->wrEffectiveTo : null,
                'is_active' => $this->wrIsActive,
            ], $this->actor());
        } catch (DomainException $exception) {
            $this->addError('wrCode', $exception->getMessage());

            return;
        }

        $this->reset([
            'showWithholdingRuleForm', 'withholdingRuleId', 'wrCode', 'wrName', 'wrNameFr',
            'wrWithholdingType', 'wrRateBp', 'wrBase', 'wrAppliesTo', 'wrMinimumBase', 'wrPriority',
            'wrLegalRef', 'wrEffectiveFrom', 'wrEffectiveTo', 'wrIsActive',
        ]);
        session()->flash('status', 'Withholding rule saved.');
    }

    public function confirmWithholdingRule(int $withholdingRuleId, ConfigureWithholdingRule $action): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        try {
            $action->confirm($withholdingRuleId, $this->actor());
        } catch (DomainException $exception) {
            $this->addError('withholdingConfirm', $exception->getMessage());

            return;
        }

        session()->flash('status', 'Withholding rule activated.');
    }

    public function toggleWithholdingProfileForm(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $this->showWithholdingProfileForm = ! $this->showWithholdingProfileForm;
    }

    public function saveWithholdingProfile(ConfigureWithholdingProfile $action): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $rules = [];

        foreach (array_filter(array_map('trim', explode(',', $this->wpRulesCsv))) as $pair) {
            $parts = explode(':', $pair);

            if (count($parts) !== 2) {
                $this->addError('wpRulesCsv', 'Rules must be "rule_id:sequence" pairs separated by commas.');

                return;
            }

            $rules[] = ['withholding_rule_id' => (int) $parts[0], 'sequence' => (int) $parts[1]];
        }

        try {
            $action->handle($this->withholdingProfileId, [
                'code' => $this->wpCode,
                'name' => $this->wpName,
                'name_fr' => $this->wpNameFr,
            ], $rules, $this->actor());
        } catch (DomainException $exception) {
            $this->addError('wpCode', $exception->getMessage());

            return;
        }

        $this->reset(['showWithholdingProfileForm', 'withholdingProfileId', 'wpCode', 'wpName', 'wpNameFr', 'wpRulesCsv']);
        session()->flash('status', 'Withholding profile saved.');
    }

    public function toggleTaxSettingsForm(): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        $this->showTaxSettingsForm = ! $this->showTaxSettingsForm;

        if ($this->showTaxSettingsForm) {
            $settings = TaxSettings::current();
            $this->tsWithholdingRecognition = $settings?->withholding_recognition?->value ?? '';
            $this->tsProrataRounding = $settings?->prorata_rounding?->value ?? '';
        }
    }

    public function saveTaxSettings(ConfigureTaxSettings $action): void
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        try {
            $action->handle([
                'withholding_recognition' => $this->tsWithholdingRecognition !== '' ? $this->tsWithholdingRecognition : null,
                'prorata_rounding' => $this->tsProrataRounding !== '' ? $this->tsProrataRounding : null,
            ], $this->actor());
        } catch (DomainException $exception) {
            $this->addError('tsWithholdingRecognition', $exception->getMessage());

            return;
        }

        $this->reset(['showTaxSettingsForm', 'tsWithholdingRecognition', 'tsProrataRounding']);
        session()->flash('status', 'Tax settings saved.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = Auth::user();

        return $user->toAuditActor();
    }

    public function render(): mixed
    {
        $identity = FiscalIdentityModel::current();
        $settings = TaxSettings::current();

        $taxCodes = TaxCode::query()->orderBy('code')->orderBy('effective_from')->get();
        $withholdingRules = WithholdingRule::query()->orderBy('code')->orderBy('effective_from')->get();
        $withholdingProfiles = WithholdingProfile::query()->with('profileRules')->orderBy('code')->get();

        // Fiscal-year codes for prorata display: Accounting's table, so a
        // DB::table read (00-core §6.2), never its model.
        $proratas = VatProrata::query()->orderByDesc('fiscal_year_id')->get();
        $fiscalYearCodes = DB::table('fiscal_years')
            ->whereIn('id', $proratas->pluck('fiscal_year_id')->all())
            ->pluck('code', 'id');

        $obligations = TaxObligation::query()->with('declarationType')->where('is_archived', false)->get();

        return view('livewire.tax.tax-configuration', [
            'identity' => $identity,
            'settings' => $settings,
            'taxCodes' => $taxCodes,
            'withholdingRules' => $withholdingRules,
            'withholdingProfiles' => $withholdingProfiles,
            'proratas' => $proratas,
            'fiscalYearCodes' => $fiscalYearCodes,
            'obligations' => $obligations,
            // The "not configured - blocks use" badges (00-core §16).
            'identityConfigured' => $identity?->isConfirmed() ?? false,
            'taxCodesConfigured' => $taxCodes->where('is_active', true)->isNotEmpty(),
            'withholdingConfigured' => $withholdingRules->whereNotNull('confirmed_at')->isNotEmpty(),
            'recognitionConfigured' => $settings?->withholding_recognition !== null,
            'prorataRoundingConfigured' => $settings?->prorata_rounding !== null,
            'prorataConfigured' => $proratas->whereNotNull('confirmed_at')->isNotEmpty(),
        ]);
    }
}
