<?php

declare(strict_types=1);

use App\Modules\Fees\Livewire\Invoices\Index;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/*
 * 04-fees §2.1 - `fee_categories.display_order` is what every category list
 * sorts by, and the invoices screen used to pass a literal 0 to
 * CreateFeeCategory, making it unreachable. The form now carries the field.
 */

it('creates a fee category with the display order the form carries', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar);

    Livewire::actingAs($bursar)
        ->test(Index::class)
        ->set('categoryCode', 'BOARD')
        ->set('categoryName', 'Boarding')
        ->set('categoryNameFr', 'Internat')
        ->set('categoryDisplayOrder', '30')
        ->call('createCategory')
        ->assertHasNoErrors();

    expect((int) DB::table('fee_categories')->where('code', 'BOARD')->value('display_order'))->toBe(30);
});

it('defaults the display order to zero', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar);

    Livewire::actingAs($bursar)
        ->test(Index::class)
        ->set('categoryCode', 'TUIT')
        ->set('categoryName', 'Tuition')
        ->set('categoryNameFr', 'Scolarité')
        ->call('createCategory')
        ->assertHasNoErrors();

    expect((int) DB::table('fee_categories')->where('code', 'TUIT')->value('display_order'))->toBe(0);
});
