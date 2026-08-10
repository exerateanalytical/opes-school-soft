<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Models\Item;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory item detail/show page at /inventory/items/{item}, gated
 * `inventory.view` (the same read ability Index.php's screen is gated on).
 *
 * Mirrors the Students\Show / Assets\Show pattern: a single Eloquent model
 * bound by mount(), plus query-builder reads for cross-module-owned tables
 * (stock_balances/stock_movements/store_locations all live in this module,
 * so Eloquent is fine for the read side, but the balance/movement rows are
 * pulled as query-builder rows the same way Index.php does, to keep the
 * shapes identical between the list and detail screens).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    use WithPagination;

    public Item $item;

    public function mount(Item $item): void
    {
        Gate::authorize(InventoryPermission::VIEW);

        $this->item = $item;
    }

    /**
     * Stock balance per location: quantity and value on hand, joined to
     * store_locations for the location name. Bounded by the number of
     * store locations in the school, never paginated (00-core 6.2 rule 8
     * is about unbounded collections; this one is naturally small).
     *
     * @return list<array{location_id: int, location_name: string, location_code: string, quantity_on_hand: string, value_on_hand: int}>
     */
    private function balancesByLocation(): array
    {
        $rows = [];

        foreach (
            DB::table('stock_balances as sb')
                ->join('store_locations as sl', 'sl.id', '=', 'sb.store_location_id')
                ->where('sb.item_id', $this->item->id)
                ->orderBy('sl.name')
                ->get(['sl.id as location_id', 'sl.name as location_name', 'sl.code as location_code', 'sb.quantity_on_hand', 'sb.value_on_hand'])
            as $row
        ) {
            /** @var object{location_id: int|string, location_name: string, location_code: string, quantity_on_hand: string, value_on_hand: int|string} $row */
            $rows[] = [
                'location_id' => (int) $row->location_id,
                'location_name' => $row->location_name,
                'location_code' => $row->location_code,
                'quantity_on_hand' => $row->quantity_on_hand,
                'value_on_hand' => (int) $row->value_on_hand,
            ];
        }

        return $rows;
    }

    /** Current total quantity on hand across all locations, for the Item Card. */
    private function totalQuantityOnHand(): string
    {
        $total = DB::table('stock_balances')
            ->where('item_id', $this->item->id)
            ->sum('quantity_on_hand');

        return (string) $total;
    }

    /**
     * Full stock movement history for this item, paginated - the same
     * columns/order Index.php's "Stock Movements" tab uses.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function movements(): LengthAwarePaginator
    {
        return DB::table('stock_movements as m')
            ->join('store_locations as sl', 'sl.id', '=', 'm.store_location_id')
            ->where('m.item_id', $this->item->id)
            ->orderByDesc('m.moved_on')
            ->orderByDesc('m.id')
            ->select([
                'm.id', 'm.movement_type', 'm.moved_on', 'm.quantity',
                'm.total_cost', 'm.document_ref', 'sl.name as location_name',
            ])
            ->paginate(25, pageName: 'movementsPage');
    }

    public function exportItemCardPdf(): Response
    {
        Gate::authorize(InventoryPermission::VIEW);

        return PdfExport::download(
            'Item Card — '.$this->item->item_code,
            ['Field', 'Value'],
            $this->itemCardRows(),
            'item-card-'.$this->item->item_code.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function itemCardRows(): iterable
    {
        yield ['Item code', $this->item->item_code];
        yield ['Name', $this->item->name];
        yield ['Category', $this->item->category?->name ?? '—'];
        yield ['Unit of measure', $this->item->unit?->code ?? '—'];
        yield ['Reorder level', $this->item->reorder_level];
        yield ['Status', ucfirst($this->item->status->value)];
        yield ['Total quantity on hand', $this->totalQuantityOnHand()];
    }

    public function render(): mixed
    {
        return view('livewire.inventory.show', [
            'balances' => $this->balancesByLocation(),
            'totalQuantityOnHand' => $this->totalQuantityOnHand(),
            'movements' => $this->movements(),
        ]);
    }
}
