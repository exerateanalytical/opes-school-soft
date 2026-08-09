<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\HR\Domain\LeaveEntryType;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Payroll\Actions\PostLeaveProvision;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\ProvisionAccountsUnconfigured;
use App\Modules\Payroll\Models\PayrollComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;

require_once __DIR__.'/../HR/P11F4TestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 12.5: the accrued-leave provision CALCULATES
 * AND REPORTS but does not post until the 66x/428x accounts are confirmed
 * on the ALLOCATION_CONGE component - and once they are, it posts through
 * PostFromEvent and nowhere else.
 */

if (! function_exists('p11declProvisionFixture')) {
    /**
     * A contract with a 12-day ledger balance and 1,600,000 F of trailing
     * remuneration across two approved runs.
     *
     * @return array{user: App\Modules\Identity\Models\User}
     */
    function p11declProvisionFixture(): array
    {
        $user = p11declUser(
            PayrollPermission::RUN,
            Permission::LedgerConfigure->value,
            Permission::LedgerPost->value,
        );

        $staff = p11declStaff();
        $contract = p11declContract($staff, ['starts_on' => '2030-01-01', 'seniority_reference_date' => '2030-01-01']);

        $annual = LeaveType::query()->where('code', 'conge_annuel')->firstOrFail();

        LeaveAccrual::query()->create([
            'staff_contract_id' => $contract->id,
            'leave_type_id' => $annual->id,
            'entry_type' => LeaveEntryType::Opening,
            'delta_days' => '12.00',
            'effective_on' => '2031-01-01',
            'created_by' => $user->id,
        ]);

        // Two approved months of 800,000 F gross inside the trailing window.
        foreach (['2031-01-01', '2031-02-01'] as $month) {
            $run = p11declRun($month, 'approved');
            p11declItem($run, $staff, $contract, 800000, ['gross' => 800000]);
        }

        return ['user' => $user];
    }
}

it('computes but refuses to post while the 66x/428x accounts are unconfigured', function () {
    ['user' => $user] = p11declProvisionFixture();

    // Entitlement configured; ONLY the accounts are missing.
    p11declConfigureAccrualRate('1.50', 24);

    try {
        app(PostLeaveProvision::class)->handle('2031-03-01', p11declActor($user));

        throw new AssertionFailedError('Expected ProvisionAccountsUnconfigured.');
    } catch (ProvisionAccountsUnconfigured $e) {
        // 1,600,000 / 16 x (12 / 24) = 50,000 - computed, reported, not posted.
        expect($e->report['provision_total'])->toBe(50000)
            ->and($e->report['lines'])->toHaveCount(1)
            ->and($e->report['journal_entry_id'])->toBeNull();
    }

    expect(JournalEntry::query()->count())->toBe(0);
});

it('lists contracts as unquantified while the annual entitlement is NULL', function () {
    ['user' => $user] = p11declProvisionFixture();

    // Rate set but statutory_days left NULL - never guessed.
    p11declConfigureAccrualRate('1.50', null);

    try {
        app(PostLeaveProvision::class)->handle('2031-03-01', p11declActor($user));

        throw new AssertionFailedError('Expected ProvisionAccountsUnconfigured.');
    } catch (ProvisionAccountsUnconfigured $e) {
        expect($e->report['provision_total'])->toBe(0)
            ->and($e->report['lines'])->toBeEmpty()
            ->and($e->report['unquantified'])->toHaveCount(1)
            ->and($e->report['unquantified'][0]['why'])->toBe('annual_entitlement_unconfigured');
    }
});

it('posts Dr expense / Cr liability through PostFromEvent once the accounts are confirmed', function () {
    ['user' => $user] = p11declProvisionFixture();
    p11declConfigureAccrualRate('1.50', 24);

    // The accountant's confirmation step (12.5): map ALLOCATION_CONGE.
    $expense = p11declAccount();
    $liability = p11declAccount();
    PayrollComponent::query()->where('code', 'ALLOCATION_CONGE')->update([
        'expense_account_id' => $expense->id,
        'liability_account_id' => $liability->id,
    ]);

    p11declProvisionRule($user);

    // The provision posts AT the month end (2031-03-31): its own accounting
    // period, distinct from the two trailing months' calendars p11declRun()
    // already opened.
    p11declCalendar('2031-03-15');

    $report = app(PostLeaveProvision::class)->handle('2031-03-01', p11declActor($user));

    expect($report['provision_total'])->toBe(50000)
        ->and($report['journal_entry_id'])->not->toBeNull();

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($report['journal_entry_id']);

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        // Single posting path: rule provenance is stamped.
        ->and($entry->posting_rule_id)->not->toBeNull();

    $lines = $entry->lines()->get();
    expect($lines)->toHaveCount(2)
        ->and((int) $lines->firstWhere('account_id', $expense->id)?->debit)->toBe(50000)
        ->and((int) $lines->firstWhere('account_id', $liability->id)?->credit)->toBe(50000);
});
