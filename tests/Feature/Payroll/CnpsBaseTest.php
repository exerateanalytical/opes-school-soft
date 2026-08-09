<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\CnpsBases;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\StatutoryRateResolver;
use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 2.1/6.1 - the N1 fix: TWO CNPS bases, one
 * ceiling, applying to PVID/PF only. RP is uncapped, always.
 */

it('caps SBC at the ceiling for the capped base, and leaves the uncapped base alone', function (): void {
    // Example C: SBC 900,000, PVID ceiling 750,000 (@statutory-reference).
    expect(CnpsBases::cappedBase(900_000, 750_000))->toBe(750_000)
        ->and(CnpsBases::uncappedBase(900_000))->toBe(900_000);
});

it('is the identity below the ceiling', function (): void {
    // Example A: SBC 80,000, well under the 750,000 ceiling.
    expect(CnpsBases::cappedBase(80_000, 750_000))->toBe(80_000)
        ->and(CnpsBases::uncappedBase(80_000))->toBe(80_000);
});

it('treats a NULL ceiling as uncapped (4.2)', function (): void {
    expect(CnpsBases::cappedBase(5_000_000, null))->toBe(5_000_000);
});

it('N1: RP resolves on the UNCAPPED base, 900,000, not the 750,000 capped figure', function (): void {
    $periodEnd = Carbon::parse('2024-06-30');

    $rp = StatutoryRate::factory()->create([
        'code' => 'RP', 'shape' => 'percentage', 'basis' => 'cnps_uncapped',
        'employer_rate_bp' => 1_750, 'risk_class' => 'A',
        'effective_from' => '2024-01-01', 'is_verified' => true,
        'source_citation' => 'CLEISS 2024 reference values - test fixture',
    ]);

    $sbc = 900_000;
    $ceiling = 750_000;

    $cnpsCapped = CnpsBases::cappedBase($sbc, $ceiling);
    $cnpsUncapped = CnpsBases::uncappedBase($sbc);

    expect($cnpsCapped)->toBe(750_000)
        ->and($cnpsUncapped)->toBe(900_000);

    $resolver = new StatutoryRateResolver;

    $resolved = $resolver->selectFrom(
        new Collection([$rp]),
        'RP',
        $periodEnd,
        riskClass: 'A',
        cnpsRegime: CnpsRegime::EnseignementPrive,
        bandValue: $cnpsUncapped,
    );

    expect((int) $resolved->getKey())->toBe($rp->id);

    // The v1 shortfall this fixes: RP computed on the CAPPED base would
    // have been 1.75% x 750,000 = 13,125, not 1.75% x 900,000 = 15,750 -
    // a 2,625 FCFA/month shortfall accruing until a CNPS inspection finds
    // it (05-hr-payroll 6.4 Example C).
    $onUncapped = (int) round($cnpsUncapped * 1_750 / 100_000);
    $onCapped = (int) round($cnpsCapped * 1_750 / 100_000);

    expect($onUncapped)->toBe(15_750)
        ->and($onCapped)->toBe(13_125)
        ->and($onUncapped - $onCapped)->toBe(2_625);
});

it('N2: PF resolves the enseignement_prive rate (3.70%), not the general regime (7%)', function (): void {
    $periodEnd = Carbon::parse('2024-06-30');

    $pfPrivate = StatutoryRate::factory()->create([
        'code' => 'PF', 'shape' => 'percentage', 'basis' => 'cnps_capped',
        'employer_rate_bp' => 3_700, 'cnps_regime' => 'enseignement_prive',
        'effective_from' => '2024-01-01', 'is_verified' => true,
        'source_citation' => 'CLEISS 2024 reference values - test fixture',
    ]);

    $pfGeneral = StatutoryRate::factory()->create([
        'code' => 'PF', 'shape' => 'percentage', 'basis' => 'cnps_capped',
        'employer_rate_bp' => 7_000, 'cnps_regime' => 'general',
        'effective_from' => '2024-01-01', 'is_verified' => true,
        'source_citation' => 'CLEISS 2024 reference values - test fixture',
    ]);

    $resolver = new StatutoryRateResolver;
    $candidates = new Collection([$pfPrivate, $pfGeneral]);

    $resolved = $resolver->selectFrom(
        $candidates,
        'PF',
        $periodEnd,
        riskClass: null,
        cnpsRegime: CnpsRegime::EnseignementPrive,
        bandValue: null,
    );

    expect((int) $resolved->getKey())->toBe($pfPrivate->id)
        ->and($resolved->employer_rate_bp)->toBe(3_700);

    $resolvedGeneral = $resolver->selectFrom(
        $candidates,
        'PF',
        $periodEnd,
        riskClass: null,
        cnpsRegime: CnpsRegime::General,
        bandValue: null,
    );

    expect((int) $resolvedGeneral->getKey())->toBe($pfGeneral->id)
        ->and($resolvedGeneral->employer_rate_bp)->toBe(7_000);

    // Example A: PF at 80,000 x 3.70% = 2,960 (enseignement prive), never
    // the 7% general-regime rate this fixture also carries.
    $onPrivate = (int) round(80_000 * $pfPrivate->employer_rate_bp / 100_000);
    expect($onPrivate)->toBe(2_960);
});
