<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\JournalEntries\Form;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('ignores an account pick for a line index that does not exist', function (): void {
    $user = p13moneyUserAs(Role::Accountant);

    // A real postable account, so the ONLY thing wrong with the call is the
    // line index - otherwise pickAccount() returns early on a null account
    // and the test would pass without ever reaching the bug.
    $accountId = (int) DB::table('chart_of_accounts')
        ->where('is_postable', true)
        ->orderBy('code')
        ->value('id');

    expect($accountId)->toBeGreaterThan(0);

    Livewire::test(Form::class)
        ->call('pickAccount', 99, $accountId)
        ->assertHasNoErrors()
        ->assertOk();
});
