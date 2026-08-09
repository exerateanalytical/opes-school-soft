<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\HR\Actions\SaveCostAllocation;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffCostAllocation;
use App\Modules\Identity\Models\User;
use App\Support\Money\Allocator;
use App\Support\Money\Money;
use App\Support\Rate\Rate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 3.7: the analytic split of a contract's
 * employment cost. Sigma(percentage_bp) = 100% exactly, per contract per
 * effective date - a teacher working across nursery and primary must be
 * split exactly or the per-section analytic P&L is wrong.
 */

if (! function_exists('p11hrAllocUser')) {
    /** A signed-in user holding staff.manage. */
    function p11hrAllocUser(): User
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate(HrPermission::MANAGE, 'web');
        $user->givePermissionTo(HrPermission::MANAGE);

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

it('refuses a split that does not sum to exactly 100 percent', function () {
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();
    $primary = AnalyticValue::factory()->create();

    // 99.999% - off by one basis point. No tolerance: that is where the
    // wrong franc hides.
    expect(fn () => app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 60_000],
        ['analytic_value_id' => $primary->id, 'percentage_bp' => 39_999],
    ]))->toThrow(ValidationException::class);

    expect(StaffCostAllocation::query()->count())->toBe(0);
});

it('saves an exact split and reads it back in force', function () {
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();
    $primary = AnalyticValue::factory()->create();

    $rows = app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 60_000],
        ['analytic_value_id' => $primary->id, 'percentage_bp' => 40_000],
    ]);

    expect($rows)->toHaveCount(2);

    $sum = (int) StaffCostAllocation::query()
        ->where('staff_contract_id', $contract->id)
        ->inForceOn('2026-05-15')
        ->sum('percentage_bp');

    expect($sum)->toBe(Rate::SCALE);
});

it('closes the previous split when a new one takes effect', function () {
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();
    $primary = AnalyticValue::factory()->create();

    app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 100_000],
    ]);

    app(SaveCostAllocation::class)->handle($contract->id, '2026-04-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 30_000],
        ['analytic_value_id' => $primary->id, 'percentage_bp' => 70_000],
    ]);

    // History is closed, not rewritten: January still answers 100% nursery.
    $january = StaffCostAllocation::query()
        ->where('staff_contract_id', $contract->id)
        ->inForceOn('2026-02-01')
        ->get();

    expect($january)->toHaveCount(1);
    expect($january->first()?->analytic_value_id)->toBe($nursery->id);
    expect($january->first()?->effective_to?->toDateString())->toBe('2026-04-01');

    // And any date after the change sums to exactly 100% again.
    $may = (int) StaffCostAllocation::query()
        ->where('staff_contract_id', $contract->id)
        ->inForceOn('2026-05-01')
        ->sum('percentage_bp');

    expect($may)->toBe(Rate::SCALE);
});

it('refuses duplicate analytic values and unknown analytic values', function () {
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();

    expect(fn () => app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 50_000],
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 50_000],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 50_000],
        ['analytic_value_id' => 999_999, 'percentage_bp' => 50_000],
    ]))->toThrow(ValidationException::class);
});

it('refuses a non-positive percentage in the Action and at the database CHECK', function () {
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();
    $primary = AnalyticValue::factory()->create();

    expect(fn () => app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 0],
        ['analytic_value_id' => $primary->id, 'percentage_bp' => 100_000],
    ]))->toThrow(ValidationException::class);

    expect(fn () => DB::table('staff_cost_allocations')->insert([
        'staff_contract_id' => $contract->id,
        'analytic_value_id' => $nursery->id,
        'percentage_bp' => 0,
        'effective_from' => '2026-01-01',
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires staff.manage to save an allocation', function () {
    $user = User::factory()->create();
    actingAs($user);

    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();

    expect(fn () => app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 100_000],
    ]))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('allocates a computed cost exactly along the stored percentages', function () {
    // 3.7 / H3: statutory amounts compute employer-wide FIRST; cost is
    // allocated downstream with the largest-remainder Allocator so the
    // analytic parts sum EXACTLY to the computed cost. 100,001 FCFA across
    // a 60/40 split cannot lose or invent a franc.
    p11hrAllocUser();
    $contract = StaffContract::factory()->create();
    $nursery = AnalyticValue::factory()->create();
    $primary = AnalyticValue::factory()->create();

    app(SaveCostAllocation::class)->handle($contract->id, '2026-01-01', [
        ['analytic_value_id' => $nursery->id, 'percentage_bp' => 60_000],
        ['analytic_value_id' => $primary->id, 'percentage_bp' => 40_000],
    ]);

    /** @var list<int> $ratios */
    $ratios = StaffCostAllocation::query()
        ->where('staff_contract_id', $contract->id)
        ->inForceOn('2026-01-31')
        ->orderBy('id')
        ->pluck('percentage_bp')
        ->map(fn (int $bp): int => $bp)
        ->all();

    $parts = Allocator::allocate(Money::of(100_001), $ratios);

    $total = 0;
    foreach ($parts as $part) {
        $total += $part->amount();
    }

    expect($total)->toBe(100_001);
    expect($parts[0]->amount())->toBe(60_001);
    expect($parts[1]->amount())->toBe(40_000);
});
