<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\ArchiveAccount;
use App\Modules\Accounting\Actions\CreateAccount;
use App\Modules\Accounting\Actions\UpdateAccount;
use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\NormalBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('accountingUserAs')) {
    /**
     * Local helper, deliberately not shared with another module's test
     * suite - see AcademicYearTest's academicsUserAs() for the same
     * rationale (Pest test files share one global function namespace).
     */
    function accountingUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(CreateAccount::PERMISSION, 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(CreateAccount::PERMISSION);
        }

        return $user->fresh() ?? $user;
    }
}

// ---------------------------------------------------------------------
// Direct-SQL trigger tests. These bypass Eloquent and the Action layer
// entirely (raw DB::statement INSERT/UPDATE) so they prove the database
// trigger itself is the backstop - per the phase brief: "a trigger that
// only ever gets exercised through an Action that also validates in PHP
// is not proven to work as a backstop."
// ---------------------------------------------------------------------

it('CoA-1: trigger rejects a depth-1 account carrying a parent_id', function () {
    $anyId = ChartOfAccount::query()->value('id');

    expect(fn () => DB::statement(
        "INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES ('5', ?, 'x', 'x', 'asset', 'debit', 1, 0, 'XAF', NOW(), NOW())",
        [$anyId]
    ))->toThrow(QueryException::class, 'CoA-1');
});

it('CoA-1: trigger rejects a depth-2+ account with a null parent_id', function () {
    expect(fn () => DB::statement(
        "INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES ('99', NULL, 'x', 'x', 'asset', 'debit', 1, 0, 'XAF', NOW(), NOW())"
    ))->toThrow(QueryException::class, 'CoA-1');
});

it('CoA-2: trigger rejects a code that is not a strict prefix extension of its parent', function () {
    $rootOneId = ChartOfAccount::query()->where('code', '1')->value('id');

    // '99' does not start with parent '1''s code.
    expect(fn () => DB::statement(
        'INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        ['99', $rootOneId, 'x', 'x', 'asset', 'debit', 1, 0, 'XAF']
    ))->toThrow(QueryException::class, 'CoA-2');
});

it('CoA-2: trigger rejects a code that skips a depth level', function () {
    $rootNineId = ChartOfAccount::query()->where('code', '9')->value('id');
    DB::table('chart_of_accounts')->where('id', $rootNineId)->update(['is_postable' => false]);

    // '911' is 3 digits under root '9' (1 digit) - depth jumps by 2, not 1.
    expect(fn () => DB::statement(
        'INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        ['911', $rootNineId, 'x', 'x', 'analytic', 'debit', 1, 0, 'XAF']
    ))->toThrow(QueryException::class, 'CoA-2');
});

it('CoA-4: trigger rejects inserting a non-archived child under a still-postable parent', function () {
    // '11' (Report a nouveau) is seeded as a leaf, is_postable = true.
    $elevenId = (int) ChartOfAccount::query()->where('code', '11')->value('id');
    expect(ChartOfAccount::query()->findOrFail($elevenId)->is_postable)->toBeTrue();

    expect(fn () => DB::statement(
        'INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        ['111', $elevenId, 'x', 'x', 'equity', 'credit', 1, 0, 'XAF']
    ))->toThrow(QueryException::class, 'CoA-4');
});

it('CoA-4: trigger accepts the child once the parent has been flipped non-postable first', function () {
    $elevenId = ChartOfAccount::query()->where('code', '11')->value('id');
    DB::table('chart_of_accounts')->where('id', $elevenId)->update(['is_postable' => false]);

    DB::statement(
        'INSERT INTO chart_of_accounts (code, parent_id, name, name_fr, type, normal_balance, is_postable, is_system, currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        ['111', $elevenId, 'x', 'x', 'equity', 'credit', 1, 0, 'XAF']
    );

    expect(ChartOfAccount::query()->where('code', '111')->exists())->toBeTrue();
});

it('CoA-5: trigger rejects changing the code of a system account', function () {
    expect(fn () => DB::statement(
        "UPDATE chart_of_accounts SET code = '2449' WHERE code = '2442'"
    ))->toThrow(QueryException::class, 'CoA-5');

    expect(ChartOfAccount::query()->where('code', '2442')->exists())->toBeTrue();
});

it('CoA-5: trigger rejects changing the name or name_fr of a system account', function () {
    expect(fn () => DB::statement(
        "UPDATE chart_of_accounts SET name = 'Renamed' WHERE code = '2442'"
    ))->toThrow(QueryException::class, 'CoA-5');

    expect(fn () => DB::statement(
        "UPDATE chart_of_accounts SET name_fr = 'Renomme' WHERE code = '2442'"
    ))->toThrow(QueryException::class, 'CoA-5');
});

it('CoA-5: trigger allows display_alias to change on a system account', function () {
    DB::statement("UPDATE chart_of_accounts SET display_alias = 'IT gear' WHERE code = '2442'");

    expect(ChartOfAccount::query()->where('code', '2442')->value('display_alias'))->toBe('IT gear');
});

// ---------------------------------------------------------------------
// Action-level tests
// ---------------------------------------------------------------------

it('creates an account under an existing parent and flips the parent non-postable', function () {
    $user = accountingUserAs();
    actingAs($user);

    $parent = ChartOfAccount::query()->where('code', '11')->firstOrFail();
    expect($parent->is_postable)->toBeTrue();

    $account = app(CreateAccount::class)->handle(
        parentId: $parent->id,
        code: '111',
        name: 'Test sub-account',
        nameFr: 'Sous-compte de test',
        type: AccountType::Equity,
        normalBalance: NormalBalance::Credit,
        actor: $user->toAuditActor(),
    );

    expect($account->exists)->toBeTrue();
    expect($account->is_postable)->toBeTrue();
    expect($account->is_system)->toBeFalse();
    expect($parent->fresh()?->is_postable)->toBeFalse();
});

it('CoA-6: CreateAccount rejects a type inconsistent with the account_class', function () {
    $user = accountingUserAs();
    actingAs($user);

    // Parent '11' is account_class 1 (equity/liability only) - 'expense' is
    // not in the allowed list.
    $parent = ChartOfAccount::query()->where('code', '11')->firstOrFail();

    expect(fn () => app(CreateAccount::class)->handle(
        parentId: $parent->id,
        code: '111',
        name: 'x',
        nameFr: 'x',
        type: AccountType::Expense,
        normalBalance: NormalBalance::Debit,
        actor: $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'CoA-6');
});

it('CreateAccount rejects a code that is not exactly one digit longer than the parent', function () {
    $user = accountingUserAs();
    actingAs($user);

    $parent = ChartOfAccount::query()->where('code', '11')->firstOrFail();

    expect(fn () => app(CreateAccount::class)->handle(
        parentId: $parent->id,
        code: '11123',
        name: 'x',
        nameFr: 'x',
        type: AccountType::Equity,
        normalBalance: NormalBalance::Credit,
        actor: $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'CoA-2');
});

it('CreateAccount requires the accounting.manage permission', function () {
    $user = accountingUserAs(withPermission: false);
    actingAs($user);

    $parent = ChartOfAccount::query()->where('code', '11')->firstOrFail();

    expect(fn () => app(CreateAccount::class)->handle(
        parentId: $parent->id,
        code: '111',
        name: 'x',
        nameFr: 'x',
        type: AccountType::Equity,
        normalBalance: NormalBalance::Credit,
    ))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('UpdateAccount refuses to change code/name/name_fr on a system account', function () {
    $user = accountingUserAs();
    actingAs($user);

    $account = ChartOfAccount::query()->where('code', '2442')->firstOrFail();

    expect(fn () => app(UpdateAccount::class)->handle($account, ['name' => 'Renamed'], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'CoA-5');
});

it('UpdateAccount allows display_alias and notes on a system account', function () {
    $user = accountingUserAs();
    actingAs($user);

    $account = ChartOfAccount::query()->where('code', '2442')->firstOrFail();

    $updated = app(UpdateAccount::class)->handle(
        $account,
        ['display_alias' => 'IT gear', 'notes' => 'updated note'],
        $user->toAuditActor(),
    );

    expect($updated->display_alias)->toBe('IT gear');
    expect($updated->notes)->toBe('updated note');
});

it('ArchiveAccount archives a leaf account with no children', function () {
    $user = accountingUserAs();
    actingAs($user);

    $account = ChartOfAccount::query()->where('code', '2442')->firstOrFail();

    $archived = app(ArchiveAccount::class)->handle($account, $user->toAuditActor());

    expect($archived->is_archived)->toBeTrue();
    expect($archived->archived_at)->not->toBeNull();
});

it('ArchiveAccount refuses to archive an account with a non-archived child', function () {
    $user = accountingUserAs();
    actingAs($user);

    // '244' (Materiel et mobilier) has non-archived children 2441/2442.
    $account = ChartOfAccount::query()->where('code', '244')->firstOrFail();

    expect(fn () => app(ArchiveAccount::class)->handle($account, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'non-archived child');
});

it('ArchiveAccount refuses to archive an already-archived account', function () {
    $user = accountingUserAs();
    actingAs($user);

    $account = ChartOfAccount::query()->where('code', '2442')->firstOrFail();
    app(ArchiveAccount::class)->handle($account, $user->toAuditActor());

    expect(fn () => app(ArchiveAccount::class)->handle($account->fresh() ?? $account, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'already archived');
});

it('the ck_coa_partner check rejects requires_partner without is_collective', function () {
    expect(fn () => DB::statement(
        "UPDATE chart_of_accounts SET requires_partner = 1, is_collective = 0 WHERE code = '52'"
    ))->toThrow(QueryException::class);
});

it('the ck_coa_currency check rejects a non-XAF currency', function () {
    expect(fn () => DB::statement(
        "UPDATE chart_of_accounts SET currency = 'USD' WHERE code = '52'"
    ))->toThrow(QueryException::class);
});
