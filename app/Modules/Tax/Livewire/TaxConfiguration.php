<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Models\FiscalIdentity as FiscalIdentityModel;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\TaxObligation;
use App\Modules\Tax\Models\TaxSettings;
use App\Modules\Tax\Models\VatProrata;
use App\Modules\Tax\Models\WithholdingProfile;
use App\Modules\Tax\Models\WithholdingRule;
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
 * Read-only in this phase: rows are configured through the audited
 * Actions (ConfigureTaxCode, ConfigureWithholdingRule, ComputeVatProrata…)
 * - this screen makes the state legible and names what is missing.
 */
#[Layout('layouts.app')]
final class TaxConfiguration extends Component
{
    #[Url]
    public string $tab = 'tax-codes';

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
