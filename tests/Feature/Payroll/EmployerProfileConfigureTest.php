<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Actions\ConfigureEmployerProfile;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Models\EmployerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('p11rateEmployerUser')) {
    function p11rateEmployerUser(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('payroll.configure', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('payroll.configure');

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p11rateCommuneId')) {
    function p11rateCommuneId(): int
    {
        return (int) DB::table('communes')->insertGetId([
            'name' => 'Commune de test '.uniqid(),
            'region' => 'Centre',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p11rateConfigureEmployer')) {
    /**
     * @param  array{regimeConfirmed?: bool, riskClassConfirmed?: bool, effectiveFrom?: string, niu?: string}  $overrides
     */
    function p11rateConfigureEmployer(array $overrides = []): EmployerProfile
    {
        return app(ConfigureEmployerProfile::class)->handle(
            cnpsEmployerNumber: 'EMP-123-456',
            dipeNumber: 'DIPE-789',
            niu: $overrides['niu'] ?? 'M012345678901A',
            tdlCommuneId: p11rateCommuneId(),
            cnpsRegime: CnpsRegime::EnseignementPrive,
            rpRiskClass: '1',
            cnpsNotificationDocumentId: 1,
            cnpsNotificationReference: 'NOTIF-2024-001',
            effectiveFrom: $overrides['effectiveFrom'] ?? '2024-01-01',
            regimeConfirmed: $overrides['regimeConfirmed'] ?? true,
            riskClassConfirmed: $overrides['riskClassConfirmed'] ?? true,
        );
    }
}

it('records the confirmed employer profile', function () {
    actingAs(p11rateEmployerUser());

    $profile = p11rateConfigureEmployer();

    expect($profile->cnps_regime)->toBe('enseignement_prive')
        ->and($profile->rp_risk_class)->toBe('1')
        // 2.4: no default proration convention - NULL until the customer
        // decides, and preflight blocks partial-month runs meanwhile.
        ->and($profile->proration_basis)->toBeNull()
        ->and($profile->ceiling_prorates_partial_month)->toBeNull()
        // 6.5: YTD-cumulative is the default IRPP mode (H10).
        ->and($profile->irpp_mode)->toBe('ytd_cumulative');
});

it('refuses to save without the affirmative confirmations', function () {
    // 3.1: the wizard step is BLOCKING - the regime and risk class are
    // transcribed from the CNPS notification letter, confirmed, audited.
    actingAs(p11rateEmployerUser());

    p11rateConfigureEmployer(['regimeConfirmed' => false]);
})->throws(ValidationException::class);

it('closes the open profile when a new version takes effect', function () {
    actingAs(p11rateEmployerUser());

    $first = p11rateConfigureEmployer(['effectiveFrom' => '2024-01-01']);
    p11rateConfigureEmployer(['effectiveFrom' => '2025-04-01']);

    $first = EmployerProfile::query()->findOrFail($first->getKey());

    // Effective-dated, exclusive end: a reclassification applies FROM a
    // date and never rewrites prior payslips.
    expect($first->effective_to?->toDateString())->toBe('2025-04-01')
        ->and(EmployerProfile::query()->covering('2025-03-31')->count())->toBe(1)
        ->and(EmployerProfile::query()->covering('2025-04-01')->firstOrFail()->effective_from->toDateString())->toBe('2025-04-01');
});

it('rejects a profile dated before the existing history', function () {
    actingAs(p11rateEmployerUser());

    p11rateConfigureEmployer(['effectiveFrom' => '2024-06-01']);
    p11rateConfigureEmployer(['effectiveFrom' => '2024-01-01']);
})->throws(DomainException::class);

it('validates the employer NIU against the fiscal identity', function () {
    actingAs(p11rateEmployerUser());

    // Cross-module fact, read via DB::table: the employer NIU mirrors the
    // school's fiscal NIU (3.1) and a mismatch is a data-entry error.
    DB::table('fiscal_identities')->insert([
        'id' => 1,
        'niu' => 'M999999999999',
        'legal_name' => 'Ecole Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    p11rateConfigureEmployer(['niu' => 'M012345678901A']);
})->throws(ValidationException::class);
