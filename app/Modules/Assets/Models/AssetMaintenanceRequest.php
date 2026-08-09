<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\MaintenancePriority;
use App\Modules\Assets\Domain\MaintenanceResolution;
use App\Modules\Assets\Domain\MaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §2.4 - the operational maintenance record. Closing
 * as done requires the operator's explicit expense-vs-capitalise choice
 * (CHECK-backed); the money flows through Phase 5 supplier invoices.
 *
 * @property int $id
 * @property int|null $asset_id
 * @property int|null $inventory_item_id
 * @property string $title
 * @property string|null $description
 * @property int $reported_by
 * @property \Illuminate\Support\Carbon $reported_at
 * @property MaintenancePriority $priority
 * @property MaintenanceStatus $status
 * @property int|null $assigned_to_staff_id
 * @property int|null $supplier_id
 * @property int|null $estimated_cost
 * @property int|null $actual_cost
 * @property MaintenanceResolution|null $resolution
 * @property string|null $resolution_justification
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int|null $closed_by
 * @property int|null $supplier_invoice_id
 * @property string|null $idempotency_key
 */
final class AssetMaintenanceRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'inventory_item_id', 'title', 'description',
        'reported_by', 'reported_at', 'priority', 'status',
        'assigned_to_staff_id', 'supplier_id', 'estimated_cost',
        'actual_cost', 'resolution', 'resolution_justification',
        'closed_at', 'closed_by', 'supplier_invoice_id', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'inventory_item_id' => 'integer',
            'reported_by' => 'integer',
            'reported_at' => 'datetime',
            'priority' => MaintenancePriority::class,
            'status' => MaintenanceStatus::class,
            'assigned_to_staff_id' => 'integer',
            'supplier_id' => 'integer',
            'estimated_cost' => 'integer',
            'actual_cost' => 'integer',
            'resolution' => MaintenanceResolution::class,
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'supplier_invoice_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
