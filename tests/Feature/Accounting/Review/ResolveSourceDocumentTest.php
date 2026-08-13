<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ResolveSourceDocument;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Expense;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function resolveSourceUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

// Expense has no factory (verified: no HasFactory trait, no
// database/factories/ExpenseFactory.php). Built directly against the
// migration's required columns instead of inventing a factory the codebase
// lacks.
function makeExpense(?int $journalEntryId): Expense
{
    $treasuryAccount = ChartOfAccount::factory()->create();
    $creator = User::factory()->create();

    return Expense::create([
        'expense_no' => 'DEP/2030/'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'expense_date' => now()->toDateString(),
        'payee_type' => 'other',
        'payee_name' => 'Test Payee',
        'description' => 'Test expense',
        'treasury_account_id' => $treasuryAccount->id,
        'total_amount' => 1000,
        'currency' => 'XAF',
        'status' => 'draft',
        'created_by' => $creator->id,
        'journal_entry_id' => $journalEntryId,
    ]);
}

it('describes an entry no document owns as a manual entry', function () {
    actingAs(resolveSourceUser());

    $entry = JournalEntry::factory()->create();

    $reference = app(ResolveSourceDocument::class)->handle((int) $entry->id);

    expect($reference->isResolvable())->toBeFalse();
    expect($reference->label())->toBe(__('opes.accounting.review.source_manual'));
});

it('never leaks a class name or a backslash into a label', function () {
    actingAs(resolveSourceUser());

    $entry = JournalEntry::factory()->create();

    expect(app(ResolveSourceDocument::class)->handle((int) $entry->id)->label())
        ->not->toContain('\\');
});

it('links an entry owned by an expense to that expense', function () {
    actingAs(resolveSourceUser());

    $entry = JournalEntry::factory()->create();
    $expense = makeExpense($entry->id);

    $reference = app(ResolveSourceDocument::class)->handle((int) $entry->id);

    expect($reference->isResolvable())->toBeTrue();
    expect($reference->url())->toBe(route('accounting.expenses.show', ['expense' => $expense->id]));
});

it('resolves a batch without querying once per entry', function () {
    actingAs(resolveSourceUser());

    $entries = JournalEntry::factory()->count(25)->create();
    $ids = $entries->pluck('id')->map(fn ($id) => (int) $id)->all();

    DB::enableQueryLog();
    $references = app(ResolveSourceDocument::class)->forEntryIds($ids);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($references)->toHaveCount(25);
    // Bounded by the number of registered resolvers, not the number of rows.
    expect($queries)->toBeLessThanOrEqual(10);
});

it('refuses without ledger.view', function () {
    actingAs(resolveSourceUser(Role::Teacher));

    app(ResolveSourceDocument::class)->handle(1);
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
