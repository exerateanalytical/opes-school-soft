<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Livewire\Index;
use App\Modules\Inventory\Models\Item;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * The catalogue door: until CreateItem existed an item could only reach the
 * database through a seeder, so a school could not add a single item.
 */
it('creates an item with its catalogue fields persisted as stated', function (): void {
    $user = phase9StockUser();
    $categoryId = phase9StockCategoryId();
    $unitId = phase9StockUnitId();

    $item = app(CreateItem::class)->handle([
        'item_code' => 'ITM9001',
        'name' => 'Whiteboard Marker (Box)',
        'item_category_id' => $categoryId,
        'item_type' => 'merchandise',
        'unit_of_measure_id' => $unitId,
        'reorder_level' => '10.500',
        'reorder_quantity' => '50',
        'standard_sale_price' => 2500,
        'barcode' => '5901234123457',
    ], phase9StockActor($user));

    $row = DB::table('items')->where('id', $item->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->item_code)->toBe('ITM9001')
        ->and($row->name)->toBe('Whiteboard Marker (Box)')
        ->and($row->item_type)->toBe('merchandise')
        ->and($row->status)->toBe('active')
        ->and($row->reorder_level)->toBe('10.500')
        ->and($row->reorder_quantity)->toBe('50.000')
        // Integer minor units (whole FCFA), never a float.
        ->and((int) $row->standard_sale_price)->toBe(2500)
        ->and($row->image_path)->toBeNull()
        // §7.1: the display-only mirror is not an input to the catalogue door.
        ->and($row->weighted_avg_cost)->toBeNull();
});

it('refuses a duplicate item code', function (): void {
    $user = phase9StockUser();
    $existing = phase9StockItem(['item_code' => 'ITM9002']);

    expect(fn () => app(CreateItem::class)->handle([
        'item_code' => 'ITM9002',
        'name' => 'A clashing item',
        'item_category_id' => (int) $existing->item_category_id,
        'item_type' => 'consumable',
        'unit_of_measure_id' => (int) $existing->unit_of_measure_id,
    ], phase9StockActor($user)))->toThrow(DomainException::class, 'already in use');

    expect(DB::table('items')->where('item_code', 'ITM9002')->count())->toBe(1);
});

it('refuses an item category that does not exist', function (): void {
    $user = phase9StockUser();

    expect(fn () => app(CreateItem::class)->handle([
        'item_code' => 'ITM9003',
        'name' => 'An orphan item',
        'item_category_id' => 987654,
        'item_type' => 'consumable',
        'unit_of_measure_id' => phase9StockUnitId(),
    ], phase9StockActor($user)))->toThrow(DomainException::class, 'does not exist');

    expect(DB::table('items')->where('item_code', 'ITM9003')->exists())->toBeFalse();
});

it('refuses a user who does not hold inventory.manage', function (): void {
    $user = phase9StockUser(['inventory.view']);

    expect(fn () => app(CreateItem::class)->handle([
        'item_code' => 'ITM9004',
        'name' => 'A forbidden item',
        'item_category_id' => phase9StockCategoryId(),
        'item_type' => 'consumable',
        'unit_of_measure_id' => phase9StockUnitId(),
    ], phase9StockActor($user)))->toThrow(AuthorizationException::class);

    expect(DB::table('items')->where('item_code', 'ITM9004')->exists())->toBeFalse();
});

it('stores an uploaded item picture and replaces it without stranding the old file', function (): void {
    $user = phase9StockUser();
    $categoryId = phase9StockCategoryId();
    $unitId = phase9StockUnitId();

    $component = Livewire::actingAs($user)->test(Index::class)
        ->set('showItemForm', true)
        ->set('itemCode', 'ITM9005')
        ->set('itemName', 'Football (Size 5)')
        ->set('itemCategoryId', $categoryId)
        ->set('itemUnitOfMeasureId', $unitId)
        ->set('itemImageUpload', UploadedFile::fake()->image('ball.png', 300, 300))
        ->call('saveItem')
        ->assertHasNoErrors();

    $itemId = (int) DB::table('items')->where('item_code', 'ITM9005')->value('id');
    $firstPath = (string) DB::table('items')->where('id', $itemId)->value('image_path');

    expect($firstPath)->toStartWith('branding/item-itm9005-')
        ->and(Storage::disk('public')->exists($firstPath))->toBeTrue();

    $component->call('editItem', $itemId)
        ->set('itemImageUpload', UploadedFile::fake()->image('ball-2.png', 320, 320))
        ->call('saveItem')
        ->assertHasNoErrors();

    $secondPath = (string) DB::table('items')->where('id', $itemId)->value('image_path');

    expect($secondPath)->not->toBe($firstPath)
        ->and(Storage::disk('public')->exists($secondPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($firstPath))->toBeFalse();
});

it('keeps a replaced picture that a second item still references', function (): void {
    $user = phase9StockUser();
    $shared = 'branding/item-shared-0000000000000000.png';
    Storage::disk('public')->put($shared, 'not-really-an-image');

    $first = phase9StockItem(['item_code' => 'ITM9006', 'image_path' => $shared]);
    phase9StockItem(['item_code' => 'ITM9007', 'image_path' => $shared]);

    app(UpdateItem::class)->handle((int) $first->getKey(), [
        'item_code' => 'ITM9006',
        'name' => $first->name,
        'item_category_id' => (int) $first->item_category_id,
        'item_type' => 'consumable',
        'unit_of_measure_id' => (int) $first->unit_of_measure_id,
        'image_path' => null,
    ], phase9StockActor($user));

    expect(DB::table('items')->where('id', $first->getKey())->value('image_path'))->toBeNull()
        ->and(Storage::disk('public')->exists($shared))->toBeTrue();
});

it('offers a newly created item in the stock-movement dropdowns', function (): void {
    $user = phase9StockUser();

    $item = app(CreateItem::class)->handle([
        'item_code' => 'ITM9008',
        'name' => 'Chalk (Box)',
        'item_category_id' => phase9StockCategoryId(),
        'item_type' => 'consumable',
        'unit_of_measure_id' => phase9StockUnitId(),
    ], phase9StockActor($user));

    Livewire::actingAs($user)->test(Index::class)
        ->assertViewHas('itemOptions', function (array $options) use ($item): bool {
            foreach ($options as $option) {
                if ($option['id'] === (int) $item->getKey() && str_contains($option['label'], 'ITM9008')) {
                    return true;
                }
            }

            return false;
        });
});
