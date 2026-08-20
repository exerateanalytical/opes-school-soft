<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\ApproveStockTake;
use App\Modules\Inventory\Actions\ApproveStoreRequisition;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\CreateStoreRequisition;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Actions\PostStockTakeVariance;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\RecordStockTakeCounts;
use App\Modules\Inventory\Actions\StartStockTake;
use App\Modules\Inventory\Actions\TransferStock;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Support\Storage\StoredImage;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Inventory read screen at /inventory, gated `inventory.view`: KPI strip
 * (total items, items below reorder level, stock movements this month,
 * pending requisitions), filter bar, tabbed table (Items, Stock Movements,
 * Requisitions).
 *
 * This is a read-only listing screen - no write forms/modals. Every table
 * is built from DB::table() query builder calls only, never Eloquent
 * Models from other modules (ModuleBoundaryTest). One paginated query per
 * render plus the KPI aggregates; no unbounded collection reaches the view
 * (00-core 6.2 rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithFileUploads;

    /** Which table is showing: items | movements | requisitions. */
    #[Url]
    public string $tab = 'items';

    #[Url]
    public string $category = '';

    #[Url]
    public string $location = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Create/Edit Item form ───────────────────────────────────────────
    public bool $showItemForm = false;

    /** Null while creating; the row's id while editing it. */
    public ?int $editingItemId = null;

    public string $itemCode = '';

    public string $itemName = '';

    public string $itemBarcode = '';

    public ?int $itemCategoryId = null;

    public string $itemType = 'consumable';

    public ?int $itemUnitOfMeasureId = null;

    public bool $itemIsStockTracked = true;

    public string $itemReorderLevel = '';

    public string $itemReorderQuantity = '';

    public string $itemSalePrice = '';

    public string $itemStatus = 'active';

    public string $itemDescription = '';

    /** The stored, content-hashed path the item's picture is at (or ''). */
    public string $itemImagePath = '';

    public ?TemporaryUploadedFile $itemImageUpload = null;

    // ── Issue Stock form ────────────────────────────────────────────────
    public bool $showIssueForm = false;

    public ?int $issueStoreLocationId = null;

    public ?int $issueItemId = null;

    public string $issueQuantity = '';

    public string $issuedOn = '';

    public string $issueNotes = '';

    // ── New Requisition form ────────────────────────────────────────────
    public bool $showRequisitionForm = false;

    public ?int $requisitionItemId = null;

    public string $requisitionQuantity = '';

    public string $requisitionDepartment = '';

    public string $requisitionNeededOn = '';

    public string $requisitionNotes = '';

    // ── Receive Stock form ──────────────────────────────────────────────
    public bool $showReceiveForm = false;

    public ?int $receiveStoreLocationId = null;

    public ?int $receiveItemId = null;

    public string $receiveQuantity = '';

    public string $receiveUnitCost = '';

    public string $receivedOn = '';

    public string $receiveDocumentRef = '';

    // ── Transfer Stock form ─────────────────────────────────────────────
    public bool $showTransferForm = false;

    public ?int $transferFromLocationId = null;

    public ?int $transferToLocationId = null;

    public ?int $transferItemId = null;

    public string $transferQuantity = '';

    public string $transferredOn = '';

    public string $transferNotes = '';

    // ── Adjust Stock form ────────────────────────────────────────────────
    public bool $showAdjustForm = false;

    public ?int $adjustStoreLocationId = null;

    public ?int $adjustItemId = null;

    public string $adjustQuantity = '';

    public string $adjustDirection = 'out';

    public string $adjustReason = '';

    public string $adjustedOn = '';

    public string $adjustTotalCost = '';

    // ── Start Stock Take form ───────────────────────────────────────────
    public bool $showStockTakeForm = false;

    public ?int $stockTakeLocationId = null;

    public string $stockTakeCountDate = '';

    // ── Record Stock Take Counts panel ──────────────────────────────────
    /** Non-null while a stock take's count-entry panel is open. */
    public ?int $recordingStockTakeId = null;

    /** @var array<int, array{counted_quantity: string, reason_code: string}> keyed by item_id */
    public array $stockTakeCounts = [];

    public function mount(): void
    {
        Gate::authorize(InventoryPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['items', 'movements', 'requisitions', 'stock_takes'], true)
            ? $tab
            : 'items';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'location', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * The image slot's validation: `image` plus an explicit mimes list plus a
     * dimension cap, all three - `image` alone admits SVG (script-capable,
     * served from this app's own origin), the mimes list alone admits a
     * 12 000 px scan, and the dimension cap alone admits a renamed
     * executable. Same reasoning as the branding slots.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'itemImageUpload' => [
                'nullable',
                'image',
                'mimes:'.implode(',', StoredImage::ALLOWED_EXTENSIONS),
                'max:'.StoredImage::MAX_KILOBYTES,
                'dimensions:max_width='.StoredImage::MAX_DIMENSION.',max_height='.StoredImage::MAX_DIMENSION,
            ],
        ];
    }

    public function toggleItemForm(): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        if ($this->showItemForm) {
            $this->closeItemForm();

            return;
        }

        $this->resetItemForm();
        $this->showItemForm = true;
    }

    public function editItem(int $itemId): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $row = DB::table('items')->where('id', $itemId)->first();

        if ($row === null) {
            return;
        }

        $this->resetItemForm();

        $this->editingItemId = $itemId;
        $this->itemCode = (string) $row->item_code;
        $this->itemName = (string) $row->name;
        $this->itemBarcode = (string) ($row->barcode ?? '');
        $this->itemCategoryId = (int) $row->item_category_id;
        $this->itemType = (string) $row->item_type;
        $this->itemUnitOfMeasureId = (int) $row->unit_of_measure_id;
        $this->itemIsStockTracked = (bool) $row->is_stock_tracked;
        $this->itemReorderLevel = (string) $row->reorder_level;
        $this->itemReorderQuantity = (string) $row->reorder_quantity;
        $this->itemSalePrice = $row->standard_sale_price === null ? '' : (string) (int) $row->standard_sale_price;
        $this->itemStatus = (string) $row->status;
        $this->itemDescription = (string) ($row->description ?? '');
        $this->itemImagePath = (string) ($row->image_path ?? '');
        $this->showItemForm = true;
    }

    /**
     * Clear the picture from the form. The FILE is deleted at save, not here:
     * an operator who clicks Remove and then Cancel must get their image
     * back, and a delete on click cannot be undone.
     */
    public function removeItemImage(): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->itemImageUpload = null;
        $this->itemImagePath = '';
    }

    public function saveItem(CreateItem $createItem, UpdateItem $updateItem): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->validate([
            'itemCode' => ['required', 'string', 'max:30'],
            'itemName' => ['required', 'string', 'max:160'],
            'itemBarcode' => ['nullable', 'string', 'max:64'],
            'itemCategoryId' => ['required', 'integer'],
            'itemType' => ['required', 'in:consumable,equipment,merchandise'],
            'itemUnitOfMeasureId' => ['required', 'integer'],
            'itemReorderLevel' => ['nullable', 'numeric', 'gte:0'],
            'itemReorderQuantity' => ['nullable', 'numeric', 'gte:0'],
            'itemSalePrice' => ['nullable', 'numeric', 'gte:0'],
            'itemStatus' => ['required', 'in:active,discontinued,archived'],
            'itemDescription' => ['nullable', 'string', 'max:2000'],
        ] + $this->rules(), [
            'itemImageUpload.image' => (string) __('opes.inventory_item_form.image_not_an_image'),
            'itemImageUpload.mimes' => (string) __('opes.inventory_item_form.image_wrong_type', [
                'types' => strtoupper(implode(', ', StoredImage::ALLOWED_EXTENSIONS)),
            ]),
            'itemImageUpload.max' => (string) __('opes.inventory_item_form.image_too_large', [
                'kb' => StoredImage::MAX_KILOBYTES,
            ]),
            'itemImageUpload.dimensions' => (string) __('opes.inventory_item_form.image_too_big', [
                'px' => StoredImage::MAX_DIMENSION,
            ]),
        ], [
            'itemCode' => 'item code',
            'itemName' => 'name',
            'itemBarcode' => 'barcode',
            'itemCategoryId' => 'category',
            'itemType' => 'item type',
            'itemUnitOfMeasureId' => 'unit of measure',
            'itemReorderLevel' => 'reorder level',
            'itemReorderQuantity' => 'reorder quantity',
            'itemSalePrice' => 'standard sale price',
            'itemStatus' => 'status',
            'itemDescription' => 'description',
            'itemImageUpload' => 'item image',
        ]);

        $this->storeItemImageUpload();

        $attributes = [
            'item_code' => $this->itemCode,
            'name' => $this->itemName,
            'barcode' => $this->itemBarcode === '' ? null : $this->itemBarcode,
            'item_category_id' => (int) $this->itemCategoryId,
            'item_type' => $this->itemType,
            'unit_of_measure_id' => (int) $this->itemUnitOfMeasureId,
            'is_stock_tracked' => $this->itemIsStockTracked,
            'reorder_level' => $this->itemReorderLevel,
            'reorder_quantity' => $this->itemReorderQuantity,
            // Whole FCFA, integer minor units - never a float in the column.
            'standard_sale_price' => $this->itemSalePrice === '' ? null : (int) round((float) $this->itemSalePrice),
            'status' => $this->itemStatus,
            'description' => $this->itemDescription,
            'image_path' => $this->itemImagePath === '' ? null : $this->itemImagePath,
        ];

        try {
            if ($this->editingItemId === null) {
                $createItem->handle($attributes, $this->actor());
            } else {
                $updateItem->handle($this->editingItemId, $attributes, $this->actor());
            }
        } catch (DomainException $e) {
            $this->addError('itemCode', $e->getMessage());

            return;
        }

        $saved = $this->editingItemId === null ? 'Item created.' : 'Item updated.';

        $this->closeItemForm();
        $this->tab = 'items';
        $this->resetPage();
        session()->flash('status', $saved);
    }

    /**
     * Move the pending upload into permanent, content-hashed storage. The
     * temporary file is released immediately: a TemporaryUploadedFile held on
     * a public property is re-serialised into every subsequent payload.
     */
    private function storeItemImageUpload(): void
    {
        if (! $this->itemImageUpload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->itemImagePath = StoredImage::putContents(
            'item-'.$this->itemCode,
            (string) file_get_contents((string) $this->itemImageUpload->getRealPath()),
            strtolower($this->itemImageUpload->getClientOriginalExtension()),
        );

        $this->itemImageUpload->delete();
        $this->itemImageUpload = null;
    }

    private function closeItemForm(): void
    {
        $this->resetItemForm();
        $this->showItemForm = false;
    }

    private function resetItemForm(): void
    {
        $this->reset([
            'editingItemId', 'itemCode', 'itemName', 'itemBarcode', 'itemCategoryId', 'itemType',
            'itemUnitOfMeasureId', 'itemIsStockTracked', 'itemReorderLevel', 'itemReorderQuantity',
            'itemSalePrice', 'itemStatus', 'itemDescription', 'itemImagePath', 'itemImageUpload',
        ]);
        $this->resetErrorBag();
    }

    public function toggleIssueForm(): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->showIssueForm = ! $this->showIssueForm;

        if ($this->showIssueForm && $this->issuedOn === '') {
            $this->issuedOn = Carbon::now()->toDateString();
        }
    }

    public function saveIssueStock(IssueStock $issueStock): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->validate([
            'issueStoreLocationId' => ['required', 'integer'],
            'issueItemId' => ['required', 'integer'],
            'issueQuantity' => ['required', 'numeric', 'gt:0'],
            'issuedOn' => ['required', 'date'],
        ], [], [
            'issueStoreLocationId' => 'store location',
            'issueItemId' => 'item',
            'issueQuantity' => 'quantity',
            'issuedOn' => 'issue date',
        ]);

        $calendar = $this->currentCalendar($this->issuedOn);

        try {
            $issueStock->handle([
                'store_location_id' => (int) $this->issueStoreLocationId,
                'lines' => [
                    ['item_id' => (int) $this->issueItemId, 'quantity' => $this->issueQuantity],
                ],
                'issued_on' => $this->issuedOn,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
                'notes' => $this->issueNotes === '' ? null : $this->issueNotes,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('issueQuantity', $e->getMessage());

            return;
        }

        $this->reset([
            'showIssueForm', 'issueStoreLocationId', 'issueItemId', 'issueQuantity', 'issuedOn', 'issueNotes',
        ]);
        $this->tab = 'movements';
        $this->resetPage();
        session()->flash('status', 'Stock issued.');
    }

    public function toggleRequisitionForm(): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->showRequisitionForm = ! $this->showRequisitionForm;

        if ($this->showRequisitionForm && $this->requisitionNeededOn === '') {
            $this->requisitionNeededOn = Carbon::now()->addDays(3)->toDateString();
        }
    }

    public function saveRequisition(CreateStoreRequisition $createRequisition): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->validate([
            'requisitionItemId' => ['required', 'integer'],
            'requisitionQuantity' => ['required', 'numeric', 'gt:0'],
            'requisitionDepartment' => ['nullable', 'string', 'max:255'],
            'requisitionNeededOn' => ['nullable', 'date'],
        ], [], [
            'requisitionItemId' => 'item',
            'requisitionQuantity' => 'quantity',
            'requisitionDepartment' => 'department',
            'requisitionNeededOn' => 'needed by date',
        ]);

        try {
            $createRequisition->handle([
                'lines' => [
                    ['item_id' => (int) $this->requisitionItemId, 'quantity' => $this->requisitionQuantity],
                ],
                'department' => $this->requisitionDepartment === '' ? null : $this->requisitionDepartment,
                'needed_on' => $this->requisitionNeededOn === '' ? null : $this->requisitionNeededOn,
                'notes' => $this->requisitionNotes === '' ? null : $this->requisitionNotes,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('requisitionQuantity', $e->getMessage());

            return;
        }

        $this->reset([
            'showRequisitionForm', 'requisitionItemId', 'requisitionQuantity',
            'requisitionDepartment', 'requisitionNeededOn', 'requisitionNotes',
        ]);
        $this->tab = 'requisitions';
        $this->resetPage();
        session()->flash('status', 'Requisition submitted.');
    }

    public function toggleReceiveForm(): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->showReceiveForm = ! $this->showReceiveForm;

        if ($this->showReceiveForm && $this->receivedOn === '') {
            $this->receivedOn = Carbon::now()->toDateString();
        }
    }

    public function saveReceiveStock(ReceiveStock $receiveStock): void
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $this->validate([
            'receiveStoreLocationId' => ['required', 'integer'],
            'receiveItemId' => ['required', 'integer'],
            'receiveQuantity' => ['required', 'numeric', 'gt:0'],
            'receiveUnitCost' => ['required', 'numeric', 'gte:0'],
            'receivedOn' => ['required', 'date'],
        ], [], [
            'receiveStoreLocationId' => 'store location',
            'receiveItemId' => 'item',
            'receiveQuantity' => 'quantity',
            'receiveUnitCost' => 'unit cost',
            'receivedOn' => 'received date',
        ]);

        $calendar = $this->currentCalendar($this->receivedOn);
        $totalCost = (int) round(((float) $this->receiveQuantity) * ((float) $this->receiveUnitCost));

        try {
            $receiveStock->handle([
                'item_id' => (int) $this->receiveItemId,
                'store_location_id' => (int) $this->receiveStoreLocationId,
                'quantity' => $this->receiveQuantity,
                'total_cost' => $totalCost,
                'moved_on' => $this->receivedOn,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
                'document_ref' => $this->receiveDocumentRef === '' ? null : $this->receiveDocumentRef,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('receiveQuantity', $e->getMessage());

            return;
        }

        $this->reset([
            'showReceiveForm', 'receiveStoreLocationId', 'receiveItemId', 'receiveQuantity',
            'receiveUnitCost', 'receivedOn', 'receiveDocumentRef',
        ]);
        $this->tab = 'movements';
        $this->resetPage();
        session()->flash('status', 'Stock received.');
    }

    public function toggleTransferForm(): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->showTransferForm = ! $this->showTransferForm;

        if ($this->showTransferForm && $this->transferredOn === '') {
            $this->transferredOn = Carbon::now()->toDateString();
        }
    }

    public function saveTransferStock(TransferStock $transferStock): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->validate([
            'transferFromLocationId' => ['required', 'integer'],
            'transferToLocationId' => ['required', 'integer', 'different:transferFromLocationId'],
            'transferItemId' => ['required', 'integer'],
            'transferQuantity' => ['required', 'numeric', 'gt:0'],
            'transferredOn' => ['required', 'date'],
        ], [], [
            'transferFromLocationId' => 'from location',
            'transferToLocationId' => 'to location',
            'transferItemId' => 'item',
            'transferQuantity' => 'quantity',
            'transferredOn' => 'transfer date',
        ]);

        $calendar = $this->currentCalendar($this->transferredOn);

        try {
            $transferStock->handle([
                'from_location_id' => (int) $this->transferFromLocationId,
                'to_location_id' => (int) $this->transferToLocationId,
                'lines' => [
                    ['item_id' => (int) $this->transferItemId, 'quantity' => $this->transferQuantity],
                ],
                'transferred_on' => $this->transferredOn,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
                'notes' => $this->transferNotes === '' ? null : $this->transferNotes,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('transferQuantity', $e->getMessage());

            return;
        }

        $this->reset([
            'showTransferForm', 'transferFromLocationId', 'transferToLocationId', 'transferItemId',
            'transferQuantity', 'transferredOn', 'transferNotes',
        ]);
        $this->tab = 'movements';
        $this->resetPage();
        session()->flash('status', 'Stock transferred.');
    }

    public function toggleAdjustForm(): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->showAdjustForm = ! $this->showAdjustForm;

        if ($this->showAdjustForm && $this->adjustedOn === '') {
            $this->adjustedOn = Carbon::now()->toDateString();
        }
    }

    public function saveAdjustStock(AdjustStock $adjustStock): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->validate([
            'adjustStoreLocationId' => ['required', 'integer'],
            'adjustItemId' => ['required', 'integer'],
            'adjustQuantity' => ['required', 'numeric', 'gt:0'],
            'adjustDirection' => ['required', 'in:in,out'],
            'adjustReason' => ['required', 'string', 'max:255'],
            'adjustedOn' => ['required', 'date'],
            'adjustTotalCost' => ['nullable', 'numeric', 'gte:0'],
        ], [], [
            'adjustStoreLocationId' => 'store location',
            'adjustItemId' => 'item',
            'adjustQuantity' => 'quantity',
            'adjustDirection' => 'direction',
            'adjustReason' => 'reason',
            'adjustedOn' => 'adjustment date',
            'adjustTotalCost' => 'total cost',
        ]);

        $calendar = $this->currentCalendar($this->adjustedOn);

        try {
            $adjustStock->handle([
                'item_id' => (int) $this->adjustItemId,
                'store_location_id' => (int) $this->adjustStoreLocationId,
                'quantity' => $this->adjustQuantity,
                'direction' => $this->adjustDirection === 'in' ? 'in' : 'out',
                'reason' => $this->adjustReason,
                'moved_on' => $this->adjustedOn,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
                'total_cost' => $this->adjustTotalCost === '' ? null : (int) round((float) $this->adjustTotalCost),
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('adjustQuantity', $e->getMessage());

            return;
        }

        $this->reset([
            'showAdjustForm', 'adjustStoreLocationId', 'adjustItemId', 'adjustQuantity',
            'adjustDirection', 'adjustReason', 'adjustedOn', 'adjustTotalCost',
        ]);
        $this->tab = 'movements';
        $this->resetPage();
        session()->flash('status', 'Stock adjusted.');
    }

    public function toggleStockTakeForm(): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->showStockTakeForm = ! $this->showStockTakeForm;

        if ($this->showStockTakeForm && $this->stockTakeCountDate === '') {
            $this->stockTakeCountDate = Carbon::now()->toDateString();
        }
    }

    public function saveStartStockTake(StartStockTake $startStockTake): void
    {
        Gate::authorize(InventoryPermission::POST);

        $this->validate([
            'stockTakeLocationId' => ['required', 'integer'],
            'stockTakeCountDate' => ['required', 'date'],
        ], [], [
            'stockTakeLocationId' => 'store location',
            'stockTakeCountDate' => 'count date',
        ]);

        $calendar = $this->currentCalendar($this->stockTakeCountDate);

        try {
            $startStockTake->handle([
                'store_location_id' => (int) $this->stockTakeLocationId,
                'count_date' => $this->stockTakeCountDate,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('stockTakeLocationId', $e->getMessage());

            return;
        }

        $this->reset(['showStockTakeForm', 'stockTakeLocationId', 'stockTakeCountDate']);
        $this->tab = 'stock_takes';
        $this->resetPage();
        session()->flash('status', 'Stock take started; the location is frozen until it posts.');
    }

    public function startRecordingCounts(int $stockTakeId): void
    {
        Gate::authorize(InventoryPermission::POST);

        /** @var list<object{item_id: int|string, counted_quantity: string|null, reason_code: string|null}> $lines */
        $lines = DB::table('stock_take_lines')
            ->where('stock_take_id', $stockTakeId)
            ->orderBy('item_id')
            ->get(['item_id', 'counted_quantity', 'reason_code'])
            ->all();

        $counts = [];

        foreach ($lines as $line) {
            $counts[(int) $line->item_id] = [
                'counted_quantity' => $line->counted_quantity ?? '',
                'reason_code' => $line->reason_code ?? '',
            ];
        }

        $this->recordingStockTakeId = $stockTakeId;
        $this->stockTakeCounts = $counts;
    }

    public function cancelRecordingCounts(): void
    {
        $this->reset(['recordingStockTakeId', 'stockTakeCounts']);
    }

    public function saveRecordCounts(RecordStockTakeCounts $recordCounts): void
    {
        Gate::authorize(InventoryPermission::POST);

        if ($this->recordingStockTakeId === null) {
            return;
        }

        $counts = [];

        foreach ($this->stockTakeCounts as $itemId => $count) {
            if (trim((string) $count['counted_quantity']) === '') {
                $this->addError('stockTakeCounts', 'Every line needs a counted quantity before it can be saved.');

                return;
            }

            $counts[$itemId] = [
                'counted_quantity' => $count['counted_quantity'],
                'reason_code' => $count['reason_code'] === '' ? null : $count['reason_code'],
            ];
        }

        try {
            $recordCounts->handle($this->recordingStockTakeId, $counts, $this->actor());
        } catch (DomainException $e) {
            $this->addError('stockTakeCounts', $e->getMessage());

            return;
        }

        $this->reset(['recordingStockTakeId', 'stockTakeCounts']);
        $this->tab = 'stock_takes';
        $this->resetPage();
        session()->flash('status', 'Counts recorded.');
    }

    public function approveStockTake(int $stockTakeId, ApproveStockTake $approveStockTake): void
    {
        Gate::authorize(InventoryPermission::POST);

        try {
            $approveStockTake->handle($stockTakeId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('stockTake', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Stock take approved.');
    }

    public function postStockTakeVariance(int $stockTakeId, PostStockTakeVariance $postVariance): void
    {
        Gate::authorize(InventoryPermission::POST);

        try {
            $postVariance->handle($stockTakeId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('stockTake', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Stock take variance posted; the location is unfrozen.');
    }

    public function approveRequisition(int $requisitionId, ApproveStoreRequisition $approveRequisition): void
    {
        Gate::authorize(InventoryPermission::POST);

        try {
            $approveRequisition->handle($requisitionId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('requisitionQuantity', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Requisition approved.');
    }

    public function rejectRequisition(int $requisitionId, ApproveStoreRequisition $approveRequisition): void
    {
        Gate::authorize(InventoryPermission::POST);

        try {
            $approveRequisition->handle($requisitionId, $this->actor(), [], true);
        } catch (DomainException $e) {
            $this->addError('requisitionQuantity', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Requisition rejected.');
    }

    /**
     * @return array{academic_year_id: int, fiscal_year_id: int}
     */
    private function currentCalendar(string $onDate): array
    {
        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id')
            ?? DB::table('academic_years')->orderByDesc('id')->value('id');

        $fiscalYearId = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $onDate)
            ->whereDate('ends_on', '>=', $onDate)
            ->value('id')
            ?? DB::table('fiscal_years')->orderByDesc('id')->value('id');

        if ($academicYearId === null || $fiscalYearId === null) {
            throw ValidationException::withMessages([
                'issuedOn' => 'No academic or fiscal year covers this date - configure the calendars first.',
            ]);
        }

        return [
            'academic_year_id' => (int) $academicYearId,
            'fiscal_year_id' => (int) $fiscalYearId,
        ];
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'movements' => $this->movementRows(),
            'requisitions' => $this->requisitionRows(),
            'stock_takes' => $this->stockTakeRows(),
            default => $this->itemRows(),
        };
    }

    /**
     * Catalogue with category, unit and current stock balance across all
     * locations - the "Items" tab.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function itemRows(): LengthAwarePaginator
    {
        return DB::table('items as i')
            ->join('item_categories as ic', 'ic.id', '=', 'i.item_category_id')
            ->join('units_of_measure as u', 'u.id', '=', 'i.unit_of_measure_id')
            ->when($this->category !== '', fn ($q) => $q->where('i.item_category_id', (int) $this->category))
            ->when($this->status !== '', fn ($q) => $q->where('i.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('i.item_code', 'like', '%'.$this->search.'%')
                        ->orWhere('i.name', 'like', '%'.$this->search.'%')
                        ->orWhere('i.barcode', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('i.item_code')
            ->select([
                'i.id', 'i.item_code', 'i.name', 'i.item_type', 'i.status',
                'i.reorder_level', 'ic.name as category_name', 'u.code as unit_code',
            ])
            ->selectSub(
                DB::table('stock_balances')->whereColumn('item_id', 'i.id')->selectRaw('COALESCE(SUM(quantity_on_hand), 0)'),
                'quantity_on_hand'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Recent stock movements (issues/transfers/receipts/adjustments) - the
     * ledger view of the "Stock Movements" tab.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function movementRows(): LengthAwarePaginator
    {
        return DB::table('stock_movements as m')
            ->join('items as i', 'i.id', '=', 'm.item_id')
            ->join('store_locations as sl', 'sl.id', '=', 'm.store_location_id')
            ->when($this->location !== '', fn ($q) => $q->where('m.store_location_id', (int) $this->location))
            ->when($this->status !== '', fn ($q) => $q->where('m.movement_type', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('i.item_code', 'like', '%'.$this->search.'%')
                        ->orWhere('i.name', 'like', '%'.$this->search.'%')
                        ->orWhere('m.document_ref', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('m.moved_on')
            ->orderByDesc('m.id')
            ->select([
                'm.id', 'm.movement_type', 'm.moved_on', 'm.quantity', 'm.total_cost',
                'm.document_ref', 'i.item_code', 'i.name as item_name', 'sl.name as location_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Store requisitions (internal consumption requests) - the
     * "Requisitions" tab.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function requisitionRows(): LengthAwarePaginator
    {
        return DB::table('store_requisitions as sr')
            ->leftJoin('school_sections as ss', 'ss.id', '=', 'sr.school_section_id')
            ->when($this->status !== '', fn ($q) => $q->where('sr.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sr.requisition_no', 'like', '%'.$this->search.'%')
                        ->orWhere('sr.department', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('sr.needed_on')
            ->orderByDesc('sr.id')
            ->select([
                'sr.id', 'sr.requisition_no', 'sr.department', 'sr.status',
                'sr.needed_on', 'sr.created_at', 'ss.name as section_name',
            ])
            ->selectSub(
                DB::table('store_requisition_lines')->whereColumn('store_requisition_id', 'sr.id')->selectRaw('COUNT(*)'),
                'lines_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Physical stock takes (freeze -> count -> approve -> post) - the
     * "Stock Takes" tab.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function stockTakeRows(): LengthAwarePaginator
    {
        return DB::table('stock_takes as st')
            ->join('store_locations as sl', 'sl.id', '=', 'st.store_location_id')
            ->when($this->location !== '', fn ($q) => $q->where('st.store_location_id', (int) $this->location))
            ->when($this->status !== '', fn ($q) => $q->where('st.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('st.reference', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('st.count_date')
            ->orderByDesc('st.id')
            ->select([
                'st.id', 'st.reference', 'st.status', 'st.count_date',
                'sl.name as location_name',
            ])
            ->selectSub(
                DB::table('stock_take_lines')->whereColumn('stock_take_id', 'st.id')->selectRaw('COUNT(*)'),
                'lines_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Lines of the stock take whose count-entry panel is open, joined to
     * their item names for the recording form.
     *
     * @return list<array{item_id: int, item_code: string, name: string, system_quantity: string}>
     */
    private function recordingLines(): array
    {
        if ($this->recordingStockTakeId === null) {
            return [];
        }

        $lines = [];

        foreach (
            DB::table('stock_take_lines as l')
                ->join('items as i', 'i.id', '=', 'l.item_id')
                ->where('l.stock_take_id', $this->recordingStockTakeId)
                ->orderBy('i.item_code')
                ->get(['l.item_id', 'i.item_code', 'i.name', 'l.system_quantity'])
            as $row
        ) {
            /** @var object{item_id: int|string, item_code: string, name: string, system_quantity: string} $row */
            $lines[] = [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'name' => $row->name,
                'system_quantity' => $row->system_quantity,
            ];
        }

        return $lines;
    }

    /**
     * The KPI strip: total items, items below reorder level, stock
     * movements this month, pending requisitions - dataset-wide, never
     * filter-dependent inventions.
     *
     * @return array{total_items: int, below_reorder: int, movements_this_month: int, pending_requisitions: int}
     */
    private function kpis(): array
    {
        $monthStart = Carbon::today()->startOfMonth()->toDateString();

        return [
            'total_items' => (int) DB::table('items')->count(),
            'below_reorder' => (int) DB::table('items as i')
                ->leftJoin('stock_balances as sb', 'sb.item_id', '=', 'i.id')
                ->where('i.is_stock_tracked', true)
                ->groupBy('i.id', 'i.reorder_level')
                ->havingRaw('COALESCE(SUM(sb.quantity_on_hand), 0) <= i.reorder_level')
                ->get(['i.id'])
                ->count(),
            'movements_this_month' => (int) DB::table('stock_movements')
                ->where('moved_on', '>=', $monthStart)
                ->count(),
            'pending_requisitions' => (int) DB::table('store_requisitions')
                ->where('status', 'submitted')
                ->count(),

            // The reference's stock-value and category tiles.
            'stock_value' => (int) DB::table('stock_balances')->sum('value_on_hand'),
            'categories' => (int) DB::table('item_categories')->count(),

            // OUT of stock is its own state, not the bottom of "below
            // reorder": an item at zero cannot be issued at all, while one
            // under its reorder level still can. The reference gives them
            // separate tiles for that reason, and below_reorder above
            // deliberately still counts both.
            'out_of_stock' => (int) DB::table('items as i')
                ->leftJoin('stock_balances as sb', 'sb.item_id', '=', 'i.id')
                ->where('i.is_stock_tracked', true)
                ->groupBy('i.id')
                ->havingRaw('COALESCE(SUM(sb.quantity_on_hand), 0) <= 0')
                ->get(['i.id'])
                ->count(),
        ];
    }

    /**
     * Stock split by state, for the rail donut.
     *
     * Counts UNITS, not items: the question the panel answers is "how much of
     * what we hold is actually issuable", and one item with four hundred
     * units reserved matters more than four items with one unit each.
     *
     * @return list<array{label: string, value: int}>
     */
    private function stockStatusDistribution(): array
    {
        $row = DB::table('stock_balances')
            ->selectRaw('COALESCE(SUM(quantity_on_hand), 0) as on_hand, COALESCE(SUM(quantity_reserved), 0) as reserved')
            ->first();

        $onHand = (int) ($row->on_hand ?? 0);
        $reserved = (int) ($row->reserved ?? 0);

        return [
            ['label' => (string) __('opes.inventory_screen.stock_available'), 'value' => max(0, $onHand - $reserved)],
            ['label' => (string) __('opes.inventory_screen.stock_reserved'), 'value' => $reserved],
        ];
    }

    /**
     * The latest stock movements, for the rail.
     *
     * @return list<array{item: string, kind: string, quantity: int, moved_on: string}>
     */
    private function recentMovements(): array
    {
        return DB::table('stock_movements as sm')
            ->join('items as i', 'i.id', '=', 'sm.item_id')
            ->orderByDesc('sm.moved_on')
            ->orderByDesc('sm.id')
            ->limit(5)
            ->get(['i.name as item', 'sm.movement_type as kind', 'sm.quantity as quantity', 'sm.moved_on as moved_on'])
            ->map(static fn (object $r): array => [
                'item' => (string) $r->item,
                'kind' => (string) $r->kind,
                'quantity' => (int) $r->quantity,
                'moved_on' => (string) $r->moved_on,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        $options = [];

        foreach (DB::table('item_categories')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function locationOptions(): array
    {
        $options = [];

        foreach (DB::table('store_locations')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function unitOptions(): array
    {
        $options = [];

        foreach (
            DB::table('units_of_measure')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            as $row
        ) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => $row->code.' — '.$row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function itemOptions(): array
    {
        $options = [];

        foreach (
            DB::table('items')
                ->where('status', '!=', 'archived')
                ->orderBy('item_code')
                ->get(['id', 'item_code', 'name'])
            as $row
        ) {
            /** @var object{id: int|string, item_code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => $row->item_code.' — '.$row->name];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'movements' => [
                ['value' => 'receipt', 'label' => 'Receipt'],
                ['value' => 'issue', 'label' => 'Issue'],
                ['value' => 'transfer_out', 'label' => 'Transfer out'],
                ['value' => 'transfer_in', 'label' => 'Transfer in'],
                ['value' => 'adjustment_in', 'label' => 'Adjustment in'],
                ['value' => 'adjustment_out', 'label' => 'Adjustment out'],
                ['value' => 'sale', 'label' => 'Sale'],
                ['value' => 'return_in', 'label' => 'Return in'],
                ['value' => 'return_out', 'label' => 'Return out'],
                ['value' => 'opening_balance', 'label' => 'Opening balance'],
            ],
            'requisitions' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'submitted', 'label' => 'Submitted'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
                ['value' => 'fulfilled', 'label' => 'Fulfilled'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'stock_takes' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'counting', 'label' => 'Counting'],
                ['value' => 'counted', 'label' => 'Counted'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'posted', 'label' => 'Posted'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'discontinued', 'label' => 'Discontinued'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'items' => (int) DB::table('items')->count(),
            'movements' => (int) DB::table('stock_movements')->count(),
            'requisitions' => (int) DB::table('store_requisitions')->count(),
            'stock_takes' => (int) DB::table('stock_takes')->count(),
        ];

        return view('livewire.inventory.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'stockStatusDistribution' => $this->stockStatusDistribution(),
            'recentMovements' => $this->recentMovements(),
            'tabCounts' => $tabCounts,
            'categoryOptions' => $this->categoryOptions(),
            'locationOptions' => $this->locationOptions(),
            'itemOptions' => $this->itemOptions(),
            'unitOptions' => $this->unitOptions(),
            'statusOptions' => $this->statusOptions(),
            'recordingLines' => $this->recordingLines(),
        ]);
    }
}
