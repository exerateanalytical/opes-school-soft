<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Accounting\Livewire\Expenses\Index;
use App\Modules\Accounting\Models\Expense;
use App\Modules\Identity\Models\User;
use Database\Factories\TaxCodeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * RecordExpense has always accepted a per-line `tax_code_id`, and the
 * component already mapped it into the payload - but the charge-line table
 * rendered no input for it, so no voucher keyed at the desk could ever
 * carry a tax treatment. These cases drive the field through the screen.
 */

if (! function_exists('expenseFormOperator')) {
    function expenseFormOperator(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([ExpensePermission::VIEW, ExpensePermission::RECORD] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo([ExpensePermission::VIEW, ExpensePermission::RECORD]);

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('expenseFormAccountId')) {
    /** A seeded postable account in the given SYSCOHADA class. */
    function expenseFormAccountId(int $class): int
    {
        return (int) DB::table('chart_of_accounts')
            ->where('account_class', $class)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->value('id');
    }
}

it('offers the active tax codes on every charge line', function (): void {
    expenseFormOperator();

    $taxCode = (new TaxCodeFactory())->create(['code' => 'TVA1925']);
    (new TaxCodeFactory())->create(['code' => 'TVAOLD', 'is_active' => false]);

    Livewire::test(Index::class)
        ->assertSet('formLines.0.tax_code_id', '')
        ->assertSeeHtml('formLines.0.tax_code_id')
        ->assertSee($taxCode->code.' — '.$taxCode->name)
        ->assertDontSee('TVAOLD');
});

it('records the tax code chosen on a charge line', function (): void {
    $user = expenseFormOperator();

    $taxCode = (new TaxCodeFactory())->create();

    Livewire::test(Index::class)
        ->set('formDescription', 'Rame de papier A4 pour le secretariat')
        ->set('formPayeeName', 'Papeterie Mokolo')
        ->set('formTreasuryAccountId', expenseFormAccountId(5))
        ->set('formLines.0.account_id', (string) expenseFormAccountId(6))
        ->set('formLines.0.amount', '12000')
        ->set('formLines.0.tax_code_id', (string) $taxCode->getKey())
        ->call('saveExpense')
        ->assertHasNoErrors();

    $expense = Expense::query()->where('created_by', $user->getKey())->latest('id')->firstOrFail();

    expect((int) DB::table('expense_lines')
        ->where('expense_id', $expense->getKey())
        ->value('tax_code_id'))->toBe((int) $taxCode->getKey());
});
