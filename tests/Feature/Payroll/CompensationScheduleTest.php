<?php

declare(strict_types=1);

use App\Modules\HR\Models\SalaryGrade;
use App\Modules\HR\Models\StaffContract;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\HourlyRate;
use App\Modules\Payroll\Models\StaffCompensation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Schema invariants of 290009 (docs/specs/05-hr-payroll.md 5.1 / 5.5):
 * compensation is amount XOR rate, hourly rates carry EXACTLY one scope,
 * and effective dating stays well-formed. These are CHECKs, not
 * validation - the malformed row is unrepresentable from every code path.
 */

if (! function_exists('p11compGrantAttributes')) {
    /**
     * @return array<string, mixed>
     */
    function p11compGrantAttributes(): array
    {
        return [
            'staff_contract_id' => StaffContract::factory()->create()->getKey(),
            // 'BASIC' ships as a system component (290008 seed).
            'component_code' => 'BASIC',
            'amount' => 250_000,
            'rate_bp' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'granted_by' => User::factory()->create()->getKey(),
            'grant_reason' => 'initial contract salary',
        ];
    }
}

it('records an effective-dated amount grant against a system component', function () {
    $grant = StaffCompensation::query()->create(p11compGrantAttributes());

    expect($grant->amount)->toBe(250_000)
        ->and($grant->rate_bp)->toBeNull()
        ->and($grant->effective_to)->toBeNull()
        ->and($grant->component()->firstOrFail()->code)->toBe('BASIC');
});

it('rejects a grant carrying both an amount and a rate', function () {
    StaffCompensation::query()->create(array_merge(p11compGrantAttributes(), [
        'rate_bp' => 5_000,
    ]));
})->throws(QueryException::class);

it('rejects a grant carrying neither an amount nor a rate', function () {
    StaffCompensation::query()->create(array_merge(p11compGrantAttributes(), [
        'amount' => null,
    ]));
})->throws(QueryException::class);

it('rejects a grant whose effective_to does not follow effective_from', function () {
    StaffCompensation::query()->create(array_merge(p11compGrantAttributes(), [
        'effective_to' => '2026-01-01',
    ]));
})->throws(QueryException::class);

it('rejects a duplicate grant for one contract, component and start date', function () {
    $attributes = p11compGrantAttributes();

    StaffCompensation::query()->create($attributes);
    // A raise is a NEW effective_from, never a second row on the same day.
    StaffCompensation::query()->create($attributes);
})->throws(QueryException::class);

it('accepts a grade-scoped hourly rate with the other scopes NULL', function () {
    $rate = HourlyRate::query()->create([
        'scope' => 'grade',
        'salary_grade_id' => SalaryGrade::factory()->create()->getKey(),
        'rate_per_hour' => 3_000,
        'effective_from' => '2026-01-01',
    ]);

    expect($rate->rate_per_hour)->toBe(3_000)
        ->and($rate->staff_contract_id)->toBeNull()
        ->and($rate->class_level_id)->toBeNull();
});

it('rejects an hourly rate claiming two scopes at once', function () {
    HourlyRate::query()->create([
        'scope' => 'grade',
        'salary_grade_id' => SalaryGrade::factory()->create()->getKey(),
        'staff_contract_id' => StaffContract::factory()->create()->getKey(),
        'rate_per_hour' => 3_000,
        'effective_from' => '2026-01-01',
    ]);
})->throws(QueryException::class);

it('rejects an hourly rate whose scope column contradicts its scope', function () {
    // scope says staff, but only a grade id is present.
    HourlyRate::query()->create([
        'scope' => 'staff',
        'salary_grade_id' => SalaryGrade::factory()->create()->getKey(),
        'rate_per_hour' => 3_000,
        'effective_from' => '2026-01-01',
    ]);
})->throws(QueryException::class);

it('rejects an hourly rate whose effective_to does not follow effective_from', function () {
    HourlyRate::query()->create([
        'scope' => 'grade',
        'salary_grade_id' => SalaryGrade::factory()->create()->getKey(),
        'rate_per_hour' => 3_000,
        'effective_from' => '2026-01-01',
        'effective_to' => '2025-12-31',
    ]);
})->throws(QueryException::class);
