<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shell rows for every statutory deduction (docs/specs/05-hr-payroll.md 0,
 * 4.2): code, basis, shape and metadata populated, the AMOUNT COLUMNS NULL,
 * `is_verified = false` so every row is invisible to the engine and payroll
 * refuses to run until the bursar configures each one from the school's own
 * CNPS notification letter / DGI notice.
 *
 * NO REAL RATE VALUES APPEAR HERE - not 4.2%, not 750 000, not the IRPP
 * brackets. A wrong rate looks authoritative on a payslip and the school
 * pays the reassessment; an empty field stops a bursar for an afternoon
 * (05 §0). Reference values live ONLY in test fixtures tagged
 * `@statutory-reference`, never in anything `db:seed` or `migrate` loads.
 * SeederRefusalTest holds this line.
 *
 * `source_citation` on a shell names WHERE the school must look for the
 * value, not the value itself. RAV and TDL band VALUES ship absent (4.5);
 * their single shells carry the code + basis so the settings screen can
 * render its "No band table configured - payroll cannot run" empty state.
 *
 * `effective_from` is an arbitrary pre-product epoch: unverified rows never
 * resolve, so the date only anchors the shell in the settings UI. The
 * configure Action replaces it with the real effective date at verification.
 */
return new class extends Migration
{
    private const EPOCH = '2000-01-01';

    public function up(): void
    {
        $now = now();

        $shell = static fn (array $row): array => array_merge([
            'label_fr' => null,
            'bracket_basis' => null,
            'employee_rate_bp' => null,
            'employer_rate_bp' => null,
            'flat_amount' => null,
            'ceiling_amount' => null,
            'floor_amount' => null,
            'band_from' => null,
            'band_to' => null,
            'risk_class' => null,
            'cnps_regime' => null,
            'effective_from' => self::EPOCH,
            'effective_to' => null,
            'source_document_id' => null,
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'locked' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $row);

        $rows = [
            $shell([
                'code' => 'PVID',
                'label' => 'CNPS pension (vieillesse, invalidite, deces)',
                'label_fr' => 'Pension CNPS (PVID)',
                'shape' => 'percentage',
                'basis' => 'cnps_capped',
                'source_citation' => 'CNPS notification letter + current CNPS contribution schedule (rates and monthly ceiling)',
            ]),
        ];

        // PF is employer-only and PER REGIME (defect N2): the school's own
        // regime is printed on its CNPS notification letter.
        foreach (['general', 'agricole', 'enseignement_prive'] as $regime) {
            $rows[] = $shell([
                'code' => 'PF',
                'label' => 'CNPS family allowances (prestations familiales), regime '.$regime,
                'label_fr' => 'Prestations familiales CNPS, regime '.$regime,
                'shape' => 'percentage',
                'basis' => 'cnps_capped',
                'cnps_regime' => $regime,
                'source_citation' => 'CNPS notification letter (employer regime) + current CNPS contribution schedule',
            ]);
        }

        // RP is employer-only, PER RISK CLASS, and UNCAPPED (defect N1) -
        // basis cnps_uncapped and the ck_sr_rp_uncapped CHECK both encode it.
        foreach (['1', '2', '3'] as $class) {
            $rows[] = $shell([
                'code' => 'RP',
                'label' => 'CNPS occupational risk (risques professionnels), class '.$class,
                'label_fr' => 'Risques professionnels CNPS, classe '.$class,
                'shape' => 'percentage',
                'basis' => 'cnps_uncapped',
                'risk_class' => $class,
                'source_citation' => 'CNPS notification letter (assigned risk class) + current CNPS contribution schedule',
            ]);
        }

        $rows[] = $shell([
            'code' => 'IRPP',
            'label' => 'Salary income tax (IRPP) - annual progressive brackets',
            'label_fr' => 'Impot sur le revenu des personnes physiques (IRPP)',
            'shape' => 'progressive_bracket',
            'basis' => 'taxable',
            'bracket_basis' => 'annual',
            'source_citation' => 'Code General des Impots, current Loi de Finances (annual bracket table)',
        ]);

        $rows[] = $shell([
            'code' => 'CAC',
            'label' => 'Centimes additionnels communaux (on computed IRPP)',
            'label_fr' => 'Centimes additionnels communaux',
            'shape' => 'percentage',
            'basis' => 'irpp_amount',
            'source_citation' => 'Code General des Impots (CAC as a surcharge on IRPP withheld)',
        ]);

        $rows[] = $shell([
            'code' => 'CFC',
            'label' => 'Credit Foncier du Cameroun (employee and employer shares)',
            'label_fr' => 'Credit Foncier du Cameroun',
            'shape' => 'percentage',
            'basis' => 'gross',
            'source_citation' => 'Code General des Impots / CFC contribution texts (both shares)',
        ]);

        $rows[] = $shell([
            'code' => 'FNE',
            'label' => 'Fonds National de l\'Emploi (employer only)',
            'label_fr' => 'Fonds National de l\'Emploi',
            'shape' => 'percentage',
            'basis' => 'gross',
            'source_citation' => 'Code General des Impots / FNE contribution texts (employer share)',
        ]);

        // RAV bands key on GROSS, TDL bands on BASIC SALARY (2.2) - one
        // basis for both shifts most of the staff a band. Band VALUES ship
        // absent entirely (4.5); these shells only carry the code + basis.
        $rows[] = $shell([
            'code' => 'RAV',
            'label' => 'Redevance audio-visuelle (flat amount per band of gross)',
            'label_fr' => 'Redevance audio-visuelle',
            'shape' => 'flat_band',
            'basis' => 'gross',
            'source_citation' => 'DGI notice - supply the current RAV band table; no bands are configured',
        ]);

        $rows[] = $shell([
            'code' => 'TDL',
            'label' => 'Taxe de developpement local (flat amount per band of base salary)',
            'label_fr' => 'Taxe de developpement local',
            'shape' => 'flat_band',
            'basis' => 'basic',
            'source_citation' => 'Commune notice - supply the current TDL band table; no bands are configured',
        ]);

        DB::table('statutory_rates')->insert($rows);
    }

    public function down(): void
    {
        DB::table('statutory_rates')
            ->where('effective_from', self::EPOCH)
            ->where('is_verified', false)
            ->where('locked', false)
            ->delete();
    }
};
