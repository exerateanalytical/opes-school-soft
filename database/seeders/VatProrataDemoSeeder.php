<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\ComputeVatProrata;
use App\Modules\Tax\Actions\ConfirmVatProrata;
use App\Modules\Tax\Domain\ProrataBasis;
use App\Modules\Tax\Domain\ProrataRounding;
use App\Modules\Tax\Models\TaxSettings;
use App\Modules\Tax\Models\VatProrata;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A CONFIRMED provisional prorata de deduction per fiscal year.
 *
 * ComputeLineTax refuses to split input VAT without one (03-tax-procurement
 * §5.4, empty-seed refusal §11.16) - a deliberate guard, not a bug. But with
 * no prorata seeded, every supplier invoice carrying deductible VAT refuses
 * on a demo box, which reads as a broken procurement module.
 *
 * A school's activity is mixed: exempt tuition (04-fees §3.1) alongside
 * taxable ancillary sales, so the provisional basis is the honest one to
 * ship. The figures below are DEMO values, not a real computation - the
 * definitive prorata is computed at year end from actual turnover, which is
 * what RegulariseVatProrata exists to do.
 *
 * Idempotent and additive: it creates nothing for a fiscal year that already
 * has a confirmed prorata, and touches no other table.
 */
final class VatProrataDemoSeeder extends Seeder
{
    /**
     * Taxable turnover / total turnover, in minor units. 80% deductible.
     */
    private const NUMERATOR = 8_000_000;

    private const DENOMINATOR = 10_000_000;

    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin === null) {
            $this->command?->warn('VatProrataDemoSeeder: no demo admin; skipping.');

            return;
        }

        // The Actions gate on a tax permission, so the seeder must act as a
        // user that holds it rather than bypassing the gate.
        Auth::setUser($admin);
        $actor = new Actor((int) $admin->getKey(), (string) $admin->name);

        $this->ensureProrataRounding();

        $fiscalYears = DB::table('fiscal_years')->orderBy('id')->get();

        foreach ($fiscalYears as $fiscalYear) {
            $fiscalYearId = (int) $fiscalYear->id;

            $existing = VatProrata::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->whereNotNull('confirmed_at')
                ->exists();

            if ($existing) {
                $this->command?->info("Prorata for FY {$fiscalYear->code} already confirmed; skipping.");

                continue;
            }

            $prorata = app(ComputeVatProrata::class)->handle(
                fiscalYearId: $fiscalYearId,
                basis: ProrataBasis::Provisional,
                numeratorAmount: self::NUMERATOR,
                denominatorAmount: self::DENOMINATOR,
                source: 'computed',
                manualReason: null,
                actor: $actor,
            );

            app(ConfirmVatProrata::class)->handle((int) $prorata->getKey(), $actor);

            $this->command?->info(
                "Confirmed provisional prorata for FY {$fiscalYear->code} (rate_bp {$prorata->rate_bp})."
            );
        }
    }

    /**
     * ComputeVatProrata refuses while `prorata_rounding` is unset, because
     * whether the CGI rounds up to the whole percent is NEEDS VERIFICATION.
     *
     * That refusal is right for a real deployment, where the rule is a
     * decision the school takes with its accountant. Here it is set only so
     * a demo box can complete the flow, and only when nothing is configured
     * yet - a real choice already made is never overwritten.
     *
     * The value does not change this seeder's figure: 8 000 000 / 10 000 000
     * is exactly 80%, which both rounding rules return identically. It still
     * needs confirming before anyone deducts real input VAT.
     */
    private function ensureProrataRounding(): void
    {
        $settings = TaxSettings::query()->first();

        if ($settings !== null && $settings->prorata_rounding !== null) {
            return;
        }

        if ($settings === null) {
            // Singleton: the table has a CHECK (id = 1) and no
            // auto-increment, so the key must be set explicitly.
            $settings = new TaxSettings();
            $settings->id = TaxSettings::SINGLETON_ID;
        }

        $settings->prorata_rounding = ProrataRounding::UpToWholePercent;
        $settings->save();

        $this->command?->warn(
            'VatProrataDemoSeeder: set prorata_rounding to up_to_whole_percent for the demo. '
            .'This is a NEEDS-VERIFICATION statutory choice - confirm it with an accountant.'
        );
    }
}
