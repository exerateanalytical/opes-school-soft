<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\Budgets\Index;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * budgetId is a `public string` bound to a <select>, so "no budget selected"
 * is the empty string, not null. saveLine() cast it straight to (int) 0 and
 * handed that to SaveBudgetLine, whose firstOrFail() threw
 * ModelNotFoundException - which guardedCall() does NOT catch (it catches
 * only DomainException), so the exception escaped to a 500.
 */
it('refuses a budget line with a validation message when no budget is selected', function (): void {
    $user = p13moneyUserAs(Role::Accountant);

    Livewire::test(Index::class)
        ->set('budgetId', '')
        ->call('saveLine')
        ->assertHasErrors('budgetId');
});

it('refuses a budget line whose budget id does not exist', function (): void {
    $user = p13moneyUserAs(Role::Accountant);

    Livewire::test(Index::class)
        ->set('budgetId', '999999')
        ->call('saveLine')
        ->assertHasErrors('budgetId');
});
