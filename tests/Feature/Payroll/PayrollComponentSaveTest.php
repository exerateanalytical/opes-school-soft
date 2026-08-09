<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Actions\SavePayrollComponent;
use App\Modules\Payroll\Domain\PayrollFormula;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollComponentTest;
use App\Support\Expression\ExpressionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('p11rateComponentUser')) {
    function p11rateComponentUser(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('payroll.configure', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('payroll.configure');

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p11rateAllowanceAttributes')) {
    /**
     * @return array<string, mixed>
     */
    function p11rateAllowanceAttributes(string $formula = 'basic / 10'): array
    {
        return [
            'name' => 'Housing allowance',
            'type' => 'earning',
            'calculation' => 'formula',
            'formula_expression' => $formula,
            'calculation_order' => 140,
            'depends_on' => ['BASIC'],
            'is_taxable' => true,
            'is_cnps_liable' => true,
            'is_enabled' => true,
            'effective_from' => '2024-01-01',
        ];
    }
}

// ---- the 5.4 grammar wrapper (H4: this is the anti-eval() line) ----

it('parses the whitelisted arithmetic grammar', function () {
    $formula = PayrollFormula::parse('min(basic, 400000) + max(gross - sbt, 0) * 2');

    expect($formula->evaluate(['basic' => 500_000, 'gross' => 120_000, 'sbt' => 100_000]))
        ->toBe(400_000 + 20_000 * 2);
});

it('rejects identifiers outside the fixed variable set', function () {
    PayrollFormula::parse('basic + salary');
})->throws(ExpressionException::class);

it('accepts component codes as variables when declared', function () {
    $formula = PayrollFormula::parse('SENIORITY + basic', ['SENIORITY']);

    expect($formula->evaluate(['SENIORITY' => 5_000, 'basic' => 100_000]))->toBe(105_000);
});

it('rejects function calls beyond min and max', function () {
    // The shared kernel parses abs() for posting rules; the payroll
    // grammar (5.4) does not admit it.
    PayrollFormula::parse('abs(basic)');
})->throws(ExpressionException::class);

it('rejects comparisons and boolean operators', function () {
    PayrollFormula::parse('basic > 100000');
})->throws(ExpressionException::class);

it('rejects division by a literal zero at parse time', function () {
    PayrollFormula::parse('basic / 0');
})->throws(ExpressionException::class);

it('surfaces runtime division by zero instead of yielding a silent zero', function () {
    $formula = PayrollFormula::parse('basic / days_in_period');

    $formula->evaluate(['basic' => 100_000, 'days_in_period' => 0]);
})->throws(ExpressionException::class);

// ---- SavePayrollComponent ----

it('saves a formula component whose stored tests pass', function () {
    actingAs(p11rateComponentUser());

    $component = app(SavePayrollComponent::class)->handle('ALLOWANCE_HOUSING', p11rateAllowanceAttributes(), [
        ['name' => 'tenth of basic', 'inputs' => ['basic' => 250_000], 'expected' => 25_000],
    ]);

    expect($component->is_enabled)->toBeTrue()
        ->and(PayrollComponentTest::query()->where('payroll_component_id', $component->getKey())->count())->toBe(1);
});

it('rejects the save when a stored test fails', function () {
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle('ALLOWANCE_HOUSING', p11rateAllowanceAttributes(), [
        // Wrong expectation: the formula computes 25,000.
        ['name' => 'wrong vector', 'inputs' => ['basic' => 250_000], 'expected' => 24_999],
    ]);
})->throws(ValidationException::class);

it('cannot enable a formula component without a stored test', function () {
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle('ALLOWANCE_HOUSING', p11rateAllowanceAttributes(), []);
})->throws(ValidationException::class);

it('rejects a formula referencing an unknown identifier at save', function () {
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle(
        'ALLOWANCE_HOUSING',
        p11rateAllowanceAttributes('basic + system'),
        [['name' => 't', 'inputs' => ['basic' => 1], 'expected' => 1]],
    );
})->throws(ValidationException::class);

it('refuses to reorder a system component', function () {
    // 5.2: the order IS the arithmetic - CAC (410) after IRPP (400) is not
    // a preference someone may tidy.
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle('CAC', ['calculation_order' => 395]);
})->throws(DomainException::class);

it('refuses a dependency on a component that runs later', function () {
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle('ALLOWANCE_LATE', array_merge(p11rateAllowanceAttributes(), [
        'calculation_order' => 150,
        'depends_on' => ['IRPP'], // order 400 - cannot feed an earning at 150
    ]), [['name' => 't', 'inputs' => ['basic' => 10], 'expected' => 1]]);
})->throws(ValidationException::class);

it('refuses a statutory component without a known rate code', function () {
    actingAs(p11rateComponentUser());

    app(SavePayrollComponent::class)->handle('WEIRD_TAX', [
        'name' => 'Weird tax',
        'type' => 'employee_deduction',
        'calculation' => 'statutory',
        'statutory_rate_code' => 'NOPE',
        'calculation_order' => 470,
        'depends_on' => [],
        'effective_from' => '2024-01-01',
    ]);
})->throws(ValidationException::class);

it('requires the payroll.configure permission', function () {
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('payroll.configure', 'web');
    actingAs(User::factory()->create());

    app(SavePayrollComponent::class)->handle('ALLOWANCE_HOUSING', p11rateAllowanceAttributes());
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('ships NET as the one enabled system formula, with its stored test green', function () {
    // 290008 seeds NET enabled with `gross - total_employee_deductions`;
    // 290009 ships its stored test. Re-executing here proves the pair is
    // coherent - preflight check 7 will do the same before every run.
    $net = PayrollComponent::query()->where('code', 'NET')->firstOrFail();
    $formula = PayrollFormula::parse((string) $net->formula_expression);

    $stored = PayrollComponentTest::query()
        ->where('payroll_component_id', $net->getKey())
        ->get();

    expect($stored)->not->toBeEmpty();

    foreach ($stored as $test) {
        expect($formula->evaluate($test->inputs))->toBe($test->expected);
    }
});
