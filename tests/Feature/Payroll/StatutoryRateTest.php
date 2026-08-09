<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Actions\CloseAndSupersedeRate;
use App\Modules\Payroll\Actions\ConfigureStatutoryRate;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\StatutoryRateAmbiguous;
use App\Modules\Payroll\Domain\StatutoryRateCode;
use App\Modules\Payroll\Domain\StatutoryRateResolver;
use App\Modules\Payroll\Domain\StatutoryRateUnresolved;
use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('p11rateConfigurer')) {
    function p11rateConfigurer(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('payroll.configure', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('payroll.configure');

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p11rateConfigurePvid')) {
    /**
     * Completes the PVID shell with the CLEISS 2024 reference values.
     *
     * @statutory-reference 4.2% employee + 4.2% employer, ceiling 750,000
     * FCFA/month (docs/specs/05-hr-payroll.md 2.3). Test fixture ONLY -
     * these figures exist nowhere in seeders or migrations, and
     * SeederRefusalTest asserts exactly that.
     */
    function p11rateConfigurePvid(string $from = '2024-01-01', ?string $to = null): StatutoryRate
    {
        return app(ConfigureStatutoryRate::class)->handle(
            code: StatutoryRateCode::Pvid,
            effectiveFrom: $from,
            sourceCitation: 'CLEISS 2024 reference values, test fixture',
            employeeRateBp: 4_200,
            employerRateBp: 4_200,
            ceilingAmount: 750_000,
            effectiveTo: $to,
        );
    }
}

it('ships every statutory code as an unverified shell with all amount columns NULL', function () {
    $rows = StatutoryRate::query()->get();

    expect($rows)->not->toBeEmpty();

    foreach (StatutoryRateCode::cases() as $code) {
        expect($rows->where('code', $code->value))->not->toBeEmpty();
    }

    foreach ($rows as $row) {
        expect($row->is_verified)->toBeFalse()
            ->and($row->locked)->toBeFalse()
            ->and($row->employee_rate_bp)->toBeNull()
            ->and($row->employer_rate_bp)->toBeNull()
            ->and($row->flat_amount)->toBeNull()
            ->and($row->ceiling_amount)->toBeNull()
            ->and($row->floor_amount)->toBeNull()
            ->and($row->band_from)->toBeNull()
            ->and($row->band_to)->toBeNull();
    }
});

it('rejects a verified row carrying both rates and a flat amount', function () {
    StatutoryRate::factory()->create([
        'code' => 'RAV', 'shape' => 'flat_band', 'basis' => 'gross',
        'employee_rate_bp' => 1_000, 'flat_amount' => 750,
        'band_from' => 0, 'is_verified' => true,
    ]);
})->throws(QueryException::class);

it('rejects a verified row carrying neither rates nor a flat amount', function () {
    StatutoryRate::factory()->create([
        'code' => 'CFC', 'is_verified' => true,
    ]);
})->throws(QueryException::class);

it('makes an RP ceiling unrepresentable', function () {
    // Defect N1: Risques Professionnels is UNCAPPED. Not documentation - a CHECK.
    StatutoryRate::factory()->create([
        'code' => 'RP', 'basis' => 'cnps_uncapped', 'risk_class' => '1',
        'employer_rate_bp' => 1_750, 'ceiling_amount' => 750_000, 'is_verified' => true,
    ]);
})->throws(QueryException::class);

it('rejects an employee share on the employer-only codes', function () {
    StatutoryRate::factory()->create([
        'code' => 'FNE', 'basis' => 'gross',
        'employee_rate_bp' => 1_000, 'is_verified' => true,
    ]);
})->throws(QueryException::class);

it('completes the shipped shell in place when a rate is configured', function () {
    actingAs(p11rateConfigurer());

    $shellId = (int) StatutoryRate::query()->where('code', 'PVID')->value('id');

    $rate = p11rateConfigurePvid();

    expect((int) $rate->getKey())->toBe($shellId)
        ->and($rate->is_verified)->toBeTrue()
        ->and($rate->employee_rate_bp)->toBe(4_200)
        ->and($rate->ceiling_amount)->toBe(750_000)
        ->and(StatutoryRate::query()->where('code', 'PVID')->count())->toBe(1);
});

it('refuses configuration without the payroll.configure permission', function () {
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('payroll.configure', 'web');
    actingAs(User::factory()->create());

    p11rateConfigurePvid();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('refuses an overlapping verified configuration for the same key', function () {
    actingAs(p11rateConfigurer());
    p11rateConfigurePvid('2024-01-01');

    app(ConfigureStatutoryRate::class)->handle(
        code: StatutoryRateCode::Pvid,
        effectiveFrom: '2025-01-01',
        sourceCitation: 'test',
        employeeRateBp: 4_000,
        employerRateBp: 4_000,
    );
})->throws(ValidationException::class);

it('resolves nothing while a rate is unverified', function () {
    // An unverified row is ABSENT to the engine (4.2 rule 9) - even one
    // carrying amounts.
    StatutoryRate::factory()->create([
        'code' => 'CFC', 'basis' => 'gross',
        'employee_rate_bp' => 1_000, 'employer_rate_bp' => 1_500,
        'is_verified' => false,
    ]);

    app(StatutoryRateResolver::class)->resolve('CFC', Carbon::parse('2024-06-30'));
})->throws(StatutoryRateUnresolved::class);

it('selects by the payroll period END date with an exclusive effective_to', function () {
    actingAs(p11rateConfigurer());

    // @statutory-reference PVID 4.2%; successor uses a deliberately fake
    // 5.0% so the two periods are distinguishable.
    p11rateConfigurePvid('2024-01-01', '2025-01-01');

    app(ConfigureStatutoryRate::class)->handle(
        code: StatutoryRateCode::Pvid,
        effectiveFrom: '2025-01-01',
        sourceCitation: 'successor, test fixture',
        employeeRateBp: 5_000,
        employerRateBp: 5_000,
        ceilingAmount: 800_000,
    );

    $resolver = app(StatutoryRateResolver::class);

    // A December 2024 run executed in January 2025 still uses the 2024 row.
    expect($resolver->resolve('PVID', Carbon::parse('2024-12-31'))->employee_rate_bp)->toBe(4_200)
        // effective_to is EXCLUSIVE: on 1 Jan 2025 the successor rules.
        ->and($resolver->resolve('PVID', Carbon::parse('2025-01-01'))->employee_rate_bp)->toBe(5_000)
        ->and($resolver->resolve('PVID', Carbon::parse('2025-01-31'))->employee_rate_bp)->toBe(5_000);

    // Before either row existed: unresolved, no fallback.
    expect(fn () => $resolver->resolve('PVID', Carbon::parse('2023-12-31')))
        ->toThrow(StatutoryRateUnresolved::class);
});

it('discriminates RP rows by risk class and PF rows by employer regime', function () {
    actingAs(p11rateConfigurer());
    $configure = app(ConfigureStatutoryRate::class);

    // @statutory-reference RP 1.75% / 2.50% by class; PF 3.70%
    // enseignement prive (defect N2). Test fixture only.
    $configure->handle(StatutoryRateCode::Rp, '2024-01-01', 'test', employerRateBp: 1_750, riskClass: '1');
    $configure->handle(StatutoryRateCode::Rp, '2024-01-01', 'test', employerRateBp: 2_500, riskClass: '2');
    $configure->handle(StatutoryRateCode::Pf, '2024-01-01', 'test', employerRateBp: 3_700, cnpsRegime: CnpsRegime::EnseignementPrive);

    $resolver = app(StatutoryRateResolver::class);
    $periodEnd = Carbon::parse('2024-03-31');

    expect($resolver->resolve('RP', $periodEnd, riskClass: '1')->employer_rate_bp)->toBe(1_750)
        ->and($resolver->resolve('RP', $periodEnd, riskClass: '2')->employer_rate_bp)->toBe(2_500)
        ->and($resolver->resolve('PF', $periodEnd, cnpsRegime: CnpsRegime::EnseignementPrive)->employer_rate_bp)->toBe(3_700);

    // The unconfigured class and regime stay blocked - never borrowed.
    expect(fn () => $resolver->resolve('RP', $periodEnd, riskClass: '3'))
        ->toThrow(StatutoryRateUnresolved::class)
        ->and(fn () => $resolver->resolve('PF', $periodEnd, cnpsRegime: CnpsRegime::General))
        ->toThrow(StatutoryRateUnresolved::class);
});

it('selects flat bands by basis value with an exclusive upper bound', function () {
    actingAs(p11rateConfigurer());
    $configure = app(ConfigureStatutoryRate::class);

    // Deliberately FAKE band values - the real TDL table is NEEDS
    // VERIFICATION (2.4) and ships absent.
    $configure->handle(StatutoryRateCode::Tdl, '2024-01-01', 'test bands', flatAmount: 100, bandFrom: 0, bandTo: 100_000);
    $configure->handle(StatutoryRateCode::Tdl, '2024-01-01', 'test bands', flatAmount: 500, bandFrom: 100_000, bandTo: null);

    $resolver = app(StatutoryRateResolver::class);
    $periodEnd = Carbon::parse('2024-03-31');

    expect($resolver->resolve('TDL', $periodEnd, bandValue: 99_999)->flat_amount)->toBe(100)
        // band_to is EXCLUSIVE: exactly 100,000 falls in the upper band.
        ->and($resolver->resolve('TDL', $periodEnd, bandValue: 100_000)->flat_amount)->toBe(500);

    // A band row cannot match without a basis value - no guessed band.
    expect(fn () => $resolver->resolve('TDL', $periodEnd))
        ->toThrow(StatutoryRateUnresolved::class);
});

it('throws ambiguous when two verified rows cover one period end', function () {
    // Built directly through the factory - the configure Action's overlap
    // check exists precisely to prevent this state.
    StatutoryRate::factory()->create([
        'code' => 'CFC', 'basis' => 'gross', 'employee_rate_bp' => 1_000,
        'effective_from' => '2024-01-01', 'is_verified' => true,
    ]);
    StatutoryRate::factory()->create([
        'code' => 'CFC', 'basis' => 'gross', 'employee_rate_bp' => 2_000,
        'effective_from' => '2024-06-01', 'is_verified' => true,
    ]);

    app(StatutoryRateResolver::class)->resolve('CFC', Carbon::parse('2024-06-30'));
})->throws(StatutoryRateAmbiguous::class);

it('sweeps ten years of days and never finds two candidate rows', function () {
    // 4.3 property test: for every day across a 10-year window, resolution
    // finds EXACTLY one row or zero - never two. Three consecutive PVID
    // periods with distinguishable (fake) rates; the daily predicate runs
    // over preloaded rows through the resolver's real selection logic.
    actingAs(p11rateConfigurer());
    $configure = app(ConfigureStatutoryRate::class);

    p11rateConfigurePvid('2020-01-01', '2023-07-01');
    $configure->handle(StatutoryRateCode::Pvid, '2023-07-01', 'test successor', employeeRateBp: 4_500, employerRateBp: 4_500, effectiveTo: '2026-01-01');
    $configure->handle(StatutoryRateCode::Pvid, '2026-01-01', 'test successor', employeeRateBp: 5_000, employerRateBp: 5_000);

    $resolver = app(StatutoryRateResolver::class);
    $candidates = StatutoryRate::query()->where('code', 'PVID')->get();

    $day = Carbon::parse('2018-01-01');
    $end = Carbon::parse('2028-01-01');
    $resolved = 0;
    $unresolved = 0;

    while ($day->lte($end)) {
        try {
            $resolver->selectFrom($candidates, 'PVID', $day->copy());
            $resolved++;
        } catch (StatutoryRateUnresolved) {
            $unresolved++;
        }
        // StatutoryRateAmbiguous propagates and fails the test: two rows
        // covering one day is the defect this property exists to catch.

        $day->addDay();
    }

    $totalDays = (int) Carbon::parse('2018-01-01')->diffInDays(Carbon::parse('2028-01-01')) + 1;
    $coveredDays = (int) Carbon::parse('2020-01-01')->diffInDays(Carbon::parse('2028-01-01')) + 1;

    expect($resolved + $unresolved)->toBe($totalDays)
        // Every day from the first effective_from onward resolves - the
        // close-and-supersede chain leaves no gap.
        ->and($resolved)->toBe($coveredDays)
        ->and($unresolved)->toBe($totalDays - $coveredDays);
});

it('rejects every update to a locked row except closing effective_to', function () {
    actingAs(p11rateConfigurer());
    $rate = p11rateConfigurePvid();

    // The approve Action sets `locked` on first reference (4.4); simulated
    // here. OLD.locked = 0, so this write passes the trigger.
    DB::table('statutory_rates')->where('id', $rate->getKey())->update(['locked' => 1]);

    // Amount edit: rejected by the BEFORE UPDATE trigger.
    expect(fn () => DB::table('statutory_rates')->where('id', $rate->getKey())->update(['employee_rate_bp' => 9_999]))
        ->toThrow(QueryException::class, 'append-only');

    // Unlocking: rejected.
    expect(fn () => DB::table('statutory_rates')->where('id', $rate->getKey())->update(['locked' => 0]))
        ->toThrow(QueryException::class, 'append-only');

    // Rewriting effective_from: rejected.
    expect(fn () => DB::table('statutory_rates')->where('id', $rate->getKey())->update(['effective_from' => '2023-01-01']))
        ->toThrow(QueryException::class, 'append-only');

    // The ONE permitted write: effective_to from NULL to a date.
    DB::table('statutory_rates')->where('id', $rate->getKey())->update(['effective_to' => '2025-01-01']);
    expect(DB::table('statutory_rates')->where('id', $rate->getKey())->value('effective_to'))->toBe('2025-01-01');

    // And only ONCE: a closed locked row cannot be re-dated.
    expect(fn () => DB::table('statutory_rates')->where('id', $rate->getKey())->update(['effective_to' => '2026-01-01']))
        ->toThrow(QueryException::class, 'append-only');
});

it('rejects deleting a locked row', function () {
    actingAs(p11rateConfigurer());
    $rate = p11rateConfigurePvid();
    DB::table('statutory_rates')->where('id', $rate->getKey())->update(['locked' => 1]);

    DB::table('statutory_rates')->where('id', $rate->getKey())->delete();
})->throws(QueryException::class);

it('closes and supersedes a locked row as one operation', function () {
    actingAs(p11rateConfigurer());
    $rate = p11rateConfigurePvid('2024-01-01');
    DB::table('statutory_rates')->where('id', $rate->getKey())->update(['locked' => 1]);

    $successor = app(CloseAndSupersedeRate::class)->handle(
        rateId: (int) $rate->getKey(),
        supersedeOn: '2025-01-01',
        sourceCitation: 'new notification letter, test fixture',
        employeeRateBp: 4_500,
        employerRateBp: 4_500,
        ceilingAmount: 800_000,
    );

    $closed = StatutoryRate::query()->findOrFail($rate->getKey());

    expect($closed->effective_to?->toDateString())->toBe('2025-01-01')
        // History intact: the closed row still carries its original amounts.
        ->and($closed->employee_rate_bp)->toBe(4_200)
        ->and($successor->effective_from->toDateString())->toBe('2025-01-01')
        ->and($successor->employee_rate_bp)->toBe(4_500)
        ->and($successor->locked)->toBeFalse()
        ->and($successor->is_verified)->toBeTrue();

    // The pair resolves seamlessly across the boundary.
    $resolver = app(StatutoryRateResolver::class);
    expect($resolver->resolve('PVID', Carbon::parse('2024-12-31'))->employee_rate_bp)->toBe(4_200)
        ->and($resolver->resolve('PVID', Carbon::parse('2025-01-31'))->employee_rate_bp)->toBe(4_500);
});

it('refuses to supersede an unverified shell', function () {
    actingAs(p11rateConfigurer());

    $shellId = (int) StatutoryRate::query()->where('code', 'IRPP')->value('id');

    app(CloseAndSupersedeRate::class)->handle(
        rateId: $shellId,
        supersedeOn: '2025-01-01',
        sourceCitation: 'test',
        employeeRateBp: 1_000,
    );
})->throws(DomainException::class);
