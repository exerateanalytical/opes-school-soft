<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\CloseAccountingPeriod;
use App\Modules\Accounting\Actions\OpenAccountingPeriod;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function accountingPeriodUserAs(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('moves an open period to soft_locked through CloseAccountingPeriod', function () {
    $user = accountingPeriodUserAs();
    actingAs($user);

    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::Open]);

    $closed = app(CloseAccountingPeriod::class)->handle((int) $period->id, $user->toAuditActor());

    expect($closed->status)->toBe(AccountingPeriodStatus::SoftLocked);
    expect($closed->soft_locked_at)->not->toBeNull();
    expect($closed->soft_locked_by)->toBe($user->id);
});

it('moves a soft_locked period to hard_locked when $hard = true, but not an open one', function () {
    $user = accountingPeriodUserAs();
    actingAs($user);

    $open = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::Open]);

    expect(fn () => app(CloseAccountingPeriod::class)->handle((int) $open->id, $user->toAuditActor(), hard: true))
        ->toThrow(DomainException::class, 'Soft-lock happens first');

    $soft = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::SoftLocked]);
    $hardLocked = app(CloseAccountingPeriod::class)->handle((int) $soft->id, $user->toAuditActor(), hard: true);

    expect($hardLocked->status)->toBe(AccountingPeriodStatus::HardLocked);
    expect($hardLocked->hard_locked_at)->not->toBeNull();
});

it('refuses to soft-lock a period that is not open', function () {
    $user = accountingPeriodUserAs();
    actingAs($user);

    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::HardLocked]);

    expect(fn () => app(CloseAccountingPeriod::class)->handle((int) $period->id, $user->toAuditActor()))
        ->toThrow(DomainException::class);
});

it('unlocks a soft_locked period back to open with a mandatory reason', function () {
    $user = accountingPeriodUserAs();
    actingAs($user);

    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::SoftLocked]);

    expect(fn () => app(OpenAccountingPeriod::class)->handle((int) $period->id, '', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'requires a reason');

    $reopened = app(OpenAccountingPeriod::class)->handle((int) $period->id, 'Late supplier invoice needs posting', $user->toAuditActor());

    expect($reopened->status)->toBe(AccountingPeriodStatus::Open);
    expect($reopened->unlock_reason)->toBe('Late supplier invoice needs posting');
});

it('refuses to unlock a hard_locked period for anyone but Super Admin', function () {
    $user = accountingPeriodUserAs();
    actingAs($user);

    $fiscalYear = FiscalYear::factory()->create();
    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::HardLocked, 'fiscal_year_id' => $fiscalYear->id]);

    expect(fn () => app(OpenAccountingPeriod::class)->handle((int) $period->id, 'Emergency correction', $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});

it('lets Super Admin unlock a hard_locked period when the fiscal year has not been DSF-filed', function () {
    $user = accountingPeriodUserAs(Role::SuperAdmin);
    actingAs($user);

    $fiscalYear = FiscalYear::factory()->create();
    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::HardLocked, 'fiscal_year_id' => $fiscalYear->id]);

    $unlocked = app(OpenAccountingPeriod::class)->handle((int) $period->id, 'Emergency correction', $user->toAuditActor());

    expect($unlocked->status)->toBe(AccountingPeriodStatus::SoftLocked);
});

it('refuses Super Admin unlock once the fiscal year is DSF-filed', function () {
    $user = accountingPeriodUserAs(Role::SuperAdmin);
    actingAs($user);

    $fiscalYear = FiscalYear::factory()->create(['dsf_filed_at' => now()]);
    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::HardLocked, 'fiscal_year_id' => $fiscalYear->id]);

    expect(fn () => app(OpenAccountingPeriod::class)->handle((int) $period->id, 'Emergency correction', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'dsf_filed_at');
});

// --- The D3 contract: AccountingPeriod::containing / firstOpenOnOrAfter /
// lockForPosting / assertOpenForPosting, consumed by PostJournalEntry. ---

it('finds the period containing a date', function () {
    $period = AccountingPeriod::factory()->create(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    $found = AccountingPeriod::containing('2026-03-15');

    expect($found?->id)->toBe($period->id);
});

it('finds the first open period on or after a date, skipping locked ones', function () {
    $fiscalYear = FiscalYear::factory()->create();
    $locked = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31',
        'status' => AccountingPeriodStatus::HardLocked,
    ]);
    $open = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $target = AccountingPeriod::firstOpenOnOrAfter('2026-01-15');

    expect($target?->id)->toBe($open->id);
    expect($target?->id)->not->toBe($locked->id);
});

it('assertOpenForPosting passes for an open period and blocks a soft_locked one by default', function () {
    $open = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::Open]);
    $soft = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::SoftLocked]);
    $hard = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::HardLocked]);

    $open->assertOpenForPosting();
    expect(true)->toBeTrue(); // did not throw

    expect(fn () => $soft->assertOpenForPosting())->toThrow(DomainException::class);
    expect(fn () => $hard->assertOpenForPosting())->toThrow(DomainException::class);

    // Year-end Actions / accounting.post_to_soft_locked pass true.
    $soft->assertOpenForPosting(allowSoftLocked: true);
    expect(true)->toBeTrue();

    expect(fn () => $hard->assertOpenForPosting(allowSoftLocked: true))->toThrow(DomainException::class);
});

it('lockForPosting loads the period by id for use inside a posting transaction', function () {
    $period = AccountingPeriod::factory()->create(['status' => AccountingPeriodStatus::Open]);

    $locked = AccountingPeriod::lockForPosting((int) $period->id);

    expect($locked->id)->toBe($period->id);
});
