<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\ExportDipe;
use App\Modules\Payroll\Actions\GenerateStatutoryDeclarations;
use App\Modules\Payroll\Actions\RecordCnpsBenefitClaim;
use App\Modules\Payroll\Domain\CnpsClaimStatus;
use App\Modules\Payroll\Domain\CnpsClaimType;
use App\Modules\Payroll\Domain\DipeLayoutUnconfigured;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\DipeLayout;
use App\Modules\Payroll\Models\StatutoryDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../HR/P11F4TestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 11 (C5): the FULL return set materialises on
 * the compliance calendar - not just DIPE - and the DIPE export stays
 * disabled until its byte layout is verified.
 */

it('materialises the monthly return set with 15th-of-following-month deadlines', function () {
    $run = p11declRun('2031-03-01', 'approved');

    $written = app(GenerateStatutoryDeclarations::class)->handle('2031-03-01');

    expect($written)->toBe(4);

    $byType = StatutoryDeclaration::query()->get()->keyBy(fn (StatutoryDeclaration $d): string => $d->type->value);

    foreach (['dipe', 'cnps_contribution_schedule', 'dgi_monthly_salary_return'] as $type) {
        expect($byType[$type]->due_date?->toDateString())->toBe('2031-04-15');
    }

    expect($byType['dipe']->payee)->toBe('CNPS')
        ->and($byType['dgi_monthly_salary_return']->payee)->toBe('DGI')
        // TDL: per-commune schedule NEEDS VERIFICATION => NO fabricated date.
        ->and($byType['tdl_remittance']->payee)->toBe('Commune')
        ->and($byType['tdl_remittance']->due_date)->toBeNull()
        ->and($byType['dipe']->generated_from_run_ids)->toBe([$run->id]);

    // Idempotent: the dedupe UNIQUE absorbs the re-run.
    expect(app(GenerateStatutoryDeclarations::class)->handle('2031-03-01'))->toBe(0)
        ->and(StatutoryDeclaration::query()->count())->toBe(4);
});

it('adds the annual returns in December, deadlines unconfigured', function () {
    p11declCalendar('2031-12-15');

    app(GenerateStatutoryDeclarations::class)->handle('2031-12-01');

    $annual = StatutoryDeclaration::query()->whereNotNull('period_year')->get();

    expect($annual)->toHaveCount(2)
        ->and($annual->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all())
            ->toBe(['annual_salary_return', 'cnps_annual']);

    foreach ($annual as $declaration) {
        expect($declaration->period_year)->toBe(2031)
            ->and($declaration->due_date)->toBeNull()
            ->and($declaration->period_month)->toBeNull();
    }
});

it('marks unfiled declarations late once the deadline passes', function () {
    app(GenerateStatutoryDeclarations::class)->handle('2020-01-01');

    $dipe = StatutoryDeclaration::query()->where('type', 'dipe')->firstOrFail();
    expect($dipe->due_date?->toDateString())->toBe('2020-02-15')
        ->and($dipe->status->value)->toBe('late');

    // The unverified-deadline row can never be "late" - it has no deadline.
    $tdl = StatutoryDeclaration::query()->where('type', 'tdl_remittance')->firstOrFail();
    expect($tdl->status->value)->toBe('due');
});

it('keeps the DIPE export disabled until the layout is populated', function () {
    p11declUser(PayrollPermission::DECLARATION_FILE);

    // The seeded layout row exists, unpopulated and inactive (11.4).
    $layout = DipeLayout::query()->where('code', DipeLayout::MAGNETIC_CODE)->firstOrFail();
    expect($layout->fields)->toBeNull()
        ->and($layout->is_active)->toBeFalse();

    expect(fn () => app(ExportDipe::class)->handle('2031-03-01'))
        ->toThrow(DipeLayoutUnconfigured::class, 'disabled');
});

it('renders fixed-width records from snapshots once the layout is verified', function () {
    p11declUser(PayrollPermission::DECLARATION_FILE);

    $staff = p11declStaff();
    $contract = p11declContract($staff);
    $run = p11declRun('2031-03-01', 'approved');
    $itemId = p11declItem($run, $staff, $contract, 150000);

    p11declSnapshot($itemId, [
        'employee' => ['cnps_number' => 'CNPS4567'],
        'days_worked' => '26',
        'bases' => ['cnps_capped_base' => 150000],
    ]);

    // The operator's verification step the seed refuses to fake.
    DipeLayout::query()->where('code', DipeLayout::MAGNETIC_CODE)->update([
        'record_length' => 30,
        'fields' => [
            ['name' => 'cnps_number', 'source' => 'employee.cnps_number', 'offset' => 1, 'length' => 12, 'alignment' => 'left', 'padding' => ' '],
            ['name' => 'days_worked', 'source' => 'days_worked', 'offset' => 13, 'length' => 4, 'alignment' => 'right', 'padding' => '0'],
            ['name' => 'capped_base', 'source' => 'bases.cnps_capped_base', 'offset' => 17, 'length' => 10, 'alignment' => 'right', 'padding' => '0'],
        ],
        'is_active' => true,
        'verified_on' => '2031-01-15',
    ]);

    $records = app(ExportDipe::class)->handle('2031-03-01');

    expect($records)->toHaveCount(1)
        ->and($records[0])->toBe('CNPS4567    0026' . '0000150000    ')
        ->and(strlen($records[0]))->toBe(30);
});

it('refuses to export a snapshot lacking days_worked rather than writing a zero', function () {
    p11declUser(PayrollPermission::DECLARATION_FILE);

    $staff = p11declStaff();
    $contract = p11declContract($staff);
    $run = p11declRun('2031-03-01', 'approved');
    $itemId = p11declItem($run, $staff, $contract);

    p11declSnapshot($itemId, ['employee' => ['cnps_number' => 'CNPS1']]);

    DipeLayout::query()->where('code', DipeLayout::MAGNETIC_CODE)->update([
        'record_length' => 12,
        'fields' => [['name' => 'cnps_number', 'source' => 'employee.cnps_number', 'offset' => 1, 'length' => 12, 'alignment' => 'left', 'padding' => ' ']],
        'is_active' => true,
    ]);

    expect(fn () => app(ExportDipe::class)->handle('2031-03-01'))
        ->toThrow(DomainException::class, 'days_worked');
});

it('records and submits a CNPS benefit claim as an unposted receivable', function () {
    $user = p11declUser(PayrollPermission::DECLARATION_FILE);
    $staff = p11declStaff();

    $claim = app(RecordCnpsBenefitClaim::class)->handle(
        staffMemberId: $staff->id,
        claimType: CnpsClaimType::Maternity,
        periodFrom: '2031-02-01',
        periodTo: '2031-05-14',
        amountAdvanced: 280000,
        amountClaimed: 280000,
        actor: p11declActor($user),
    );

    expect($claim->status)->toBe(CnpsClaimStatus::Draft)
        // 11.6: the sub-account is NEEDS VERIFICATION - no posting yet.
        ->and($claim->journal_entry_id)->toBeNull();

    $submitted = app(RecordCnpsBenefitClaim::class)->submit($claim->id, p11declActor($user));

    expect($submitted->status)->toBe(CnpsClaimStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull();

    // The DB refuses a benefit-claim world the spec forbids: negative amounts.
    expect(fn () => DB::table('cnps_benefit_claims')->where('id', $claim->id)->update(['amount_reimbursed' => -1]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
