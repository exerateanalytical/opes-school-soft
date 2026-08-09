<?php

declare(strict_types=1);

use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md §0, the standing rule:
 *
 *   "No statutory rate, bracket, ceiling, band, barème or schedule is
 *    seeded with a value."
 *
 * These tests run AFTER full migration + db:seed and assert the database
 * contains ZERO statutory amounts. If any of them ever fails, someone has
 * seeded a "helpful" default - which looks authoritative on a payslip, a
 * DIPE and a DGI return, and the school (not the vendor) pays the
 * reassessment. Reference values belong ONLY in test fixtures tagged
 * @statutory-reference.
 */

it('leaves zero non-null statutory amounts after migrate and db:seed', function () {
    $this->seed();

    // Shells exist - the settings screen needs them to render its
    // "Not configured - payroll is blocked" empty states (9.2)...
    expect(StatutoryRate::query()->count())->toBeGreaterThan(0);

    // ...but not one carries an amount of any kind.
    $withAnyAmount = StatutoryRate::query()
        ->whereNotNull('employee_rate_bp')
        ->orWhereNotNull('employer_rate_bp')
        ->orWhereNotNull('flat_amount')
        ->orWhereNotNull('ceiling_amount')
        ->orWhereNotNull('floor_amount')
        ->orWhereNotNull('band_from')
        ->orWhereNotNull('band_to')
        ->count();

    expect($withAnyAmount)->toBe(0);
});

it('leaves every seeded rate unverified, unlocked and cited', function () {
    $this->seed();

    $rows = StatutoryRate::query()->get();

    foreach ($rows as $row) {
        // Unverified = invisible to the engine (4.2 rule 9): payroll
        // REFUSES to run rather than computing from a shell.
        expect($row->is_verified)->toBeFalse()
            ->and($row->locked)->toBeFalse()
            ->and($row->verified_by)->toBeNull()
            // The shell tells the bursar WHERE to look, never WHAT to enter.
            ->and(trim($row->source_citation))->not->toBe('');
    }
});

it('ships no RAV or TDL band rows at all', function () {
    // 4.5: band VALUES are not created until the customer supplies the
    // table from their own DGI/commune notice. One unverified shell per
    // code carries the metadata; nothing carries a band boundary.
    $this->seed();

    expect(
        StatutoryRate::query()
            ->whereIn('code', ['RAV', 'TDL'])
            ->whereNotNull('band_from')
            ->count()
    )->toBe(0);
});

it('seeds the system component set with no accounts mapped', function () {
    $this->seed();

    $components = PayrollComponent::query()->where('is_system', true)->get();

    // The full statutory chain is present (5.3)...
    foreach (['BASIC', 'PVID_EE', 'PVID_ER', 'PF', 'RP', 'IRPP', 'CAC', 'CFC_EE', 'CFC_ER', 'FNE', 'RAV', 'TDL', 'NET'] as $code) {
        expect($components->where('code', $code))->toHaveCount(1);
    }

    foreach ($components as $component) {
        // ...with accounts NULL until the accountant maps them, and NO
        // amounts anywhere - statutory components carry only the CODE of
        // the rate they resolve at run time.
        expect($component->expense_account_id)->toBeNull()
            ->and($component->liability_account_id)->toBeNull();
    }

    // CAC strictly after IRPP: its basis is the rounded withheld amount.
    $orders = $components->pluck('calculation_order', 'code');
    expect($orders['CAC'])->toBeGreaterThan($orders['IRPP']);
});

it('keeps db:seed from adding statutory rows beyond the migration shells', function () {
    $before = StatutoryRate::query()->count();
    $beforeComponents = DB::table('payroll_components')->count();

    $this->seed();

    expect(StatutoryRate::query()->count())->toBe($before)
        ->and(DB::table('payroll_components')->count())->toBe($beforeComponents);
});
