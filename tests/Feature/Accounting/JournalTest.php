<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\ConfigureJournal;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * Local helper, deliberately not shared with the other Accounting test
 * files' equivalents (Pest test files share one global function namespace).
 */
function journalUserAs(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('seeds the nine statutory journals from 02-accounting §3', function () {
    $expected = [
        'VE' => ['type' => 'sales', 'is_system' => false, 'is_active' => true],
        'AC' => ['type' => 'purchases', 'is_system' => false, 'is_active' => true],
        'CA' => ['type' => 'cash', 'is_system' => false, 'is_active' => false],
        'BQ' => ['type' => 'bank', 'is_system' => false, 'is_active' => false],
        'MM' => ['type' => 'mobile_money', 'is_system' => false, 'is_active' => false],
        'PA' => ['type' => 'payroll', 'is_system' => false, 'is_active' => true],
        'OD' => ['type' => 'operations_diverses', 'is_system' => false, 'is_active' => true],
        'AN' => ['type' => 'opening', 'is_system' => true, 'is_active' => true],
        'CL' => ['type' => 'closing', 'is_system' => true, 'is_active' => true],
    ];

    expect(Journal::query()->count())->toBe(9);

    foreach ($expected as $code => $attrs) {
        assertDatabaseHas('journals', [
            'code' => $code,
            'type' => $attrs['type'],
            'is_system' => $attrs['is_system'],
            'is_active' => $attrs['is_active'],
        ]);
    }
});

it('flags AN and AN alone plus CL as system journals', function () {
    $systemCodes = Journal::query()->where('is_system', true)->pluck('code')->sort()->values()->all();

    expect($systemCodes)->toBe(['AN', 'CL']);
});

it('blocks the CA/BQ/MM treasury CHECK at the database when a journal is active without a treasury account', function () {
    expect(function () {
        DB::table('journals')->insert([
            'code' => 'ZZ',
            'name' => 'Test bank',
            'name_fr' => 'Banque de test',
            'type' => 'bank',
            'requires_maker_checker' => false,
            'piece_no_format' => '{journal}/{fy}/{seq:6}',
            'is_system' => false,
            'is_active' => true,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

it('permits the seeded CA/BQ/MM rows precisely because they ship inactive', function () {
    // The migration itself is the regression test for this: if the CHECK
    // constraint were written without the is_active escape, the seed
    // migration would already have failed before this test file runs.
    assertDatabaseHas('journals', ['code' => 'CA', 'is_active' => false, 'treasury_account_id' => null]);
    assertDatabaseHas('journals', ['code' => 'BQ', 'is_active' => false, 'treasury_account_id' => null]);
    assertDatabaseHas('journals', ['code' => 'MM', 'is_active' => false, 'treasury_account_id' => null]);
});

it('refuses to activate a treasury-type journal through ConfigureJournal without a treasury account', function () {
    $user = journalUserAs();
    actingAs($user);

    $ca = Journal::query()->where('code', 'CA')->firstOrFail();

    expect(fn () => app(ConfigureJournal::class)->handle(
        (int) $ca->id,
        ['is_active' => true],
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'requires a treasury_account_id');
});

it('activates a treasury-type journal through ConfigureJournal once a treasury account is assigned', function () {
    $user = journalUserAs();
    actingAs($user);

    $account = ChartOfAccount::factory()->create();
    $ca = Journal::query()->where('code', 'CA')->firstOrFail();

    $updated = app(ConfigureJournal::class)->handle(
        (int) $ca->id,
        ['treasury_account_id' => $account->id, 'is_active' => true],
        $user->toAuditActor(),
    );

    expect($updated->is_active)->toBeTrue();
    expect($updated->treasury_account_id)->toBe($account->id);
    expect(AuditLog::query()->where('module', 'Accounting')->where('auditable_type', Journal::class)->count())->toBe(1);
});

it('blocks ConfigureJournal from altering AN, the system journal', function () {
    $user = journalUserAs();
    actingAs($user);

    $an = Journal::query()->where('code', 'AN')->firstOrFail();

    expect(fn () => app(ConfigureJournal::class)->handle(
        (int) $an->id,
        ['name' => 'Renamed'],
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'is not editable through ConfigureJournal');

    expect(Journal::query()->findOrFail($an->id)->name)->toBe($an->name);
});

it('blocks ConfigureJournal from altering CL, the other system journal', function () {
    $user = journalUserAs();
    actingAs($user);

    $cl = Journal::query()->where('code', 'CL')->firstOrFail();

    expect(fn () => app(ConfigureJournal::class)->handle(
        (int) $cl->id,
        ['piece_no_format' => 'X'],
        $user->toAuditActor(),
    ))->toThrow(DomainException::class);
});

it('lets an ordinary journal be reconfigured and audited', function () {
    $user = journalUserAs();
    actingAs($user);

    $od = Journal::query()->where('code', 'OD')->firstOrFail();

    $updated = app(ConfigureJournal::class)->handle(
        (int) $od->id,
        ['requires_maker_checker' => true],
        $user->toAuditActor(),
    );

    expect($updated->requires_maker_checker)->toBeTrue();
});

it('denies configuring a journal to a user without ledger.configure', function () {
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    actingAs($user);

    $od = Journal::query()->where('code', 'OD')->firstOrFail();

    expect(fn () => app(ConfigureJournal::class)->handle(
        (int) $od->id,
        ['requires_maker_checker' => true],
        $user->toAuditActor(),
    ))->toThrow(AuthorizationException::class);
});
