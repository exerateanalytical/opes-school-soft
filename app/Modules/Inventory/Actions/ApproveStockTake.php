<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StockTakeStatus;
use App\Modules\Inventory\Models\StockTake;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.10 - approval is SEGREGATED:
 * `approved_by <> counted_by`, refused in code before the status moves.
 * Material variances additionally demand a documented reason code on every
 * variant line before approval.
 */
final class ApproveStockTake
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $stockTakeId, Actor $actor): StockTake
    {
        Gate::authorize(InventoryPermission::POST);

        return DB::transaction(function () use ($stockTakeId, $actor): StockTake {
            /** @var StockTake|null $take */
            $take = StockTake::query()->lockForUpdate()->find($stockTakeId);

            if ($take === null) {
                throw new DomainException("Stock take {$stockTakeId} does not exist.");
            }

            if ($take->status !== StockTakeStatus::Counted) {
                throw new DomainException(
                    "Stock take '{$take->reference}' is {$take->status->value}; only a counted take can be approved."
                );
            }

            if ($take->counted_by === $actor->id) {
                throw new DomainException(
                    'A stock take cannot be approved by the hand that counted it (§7.10 segregation).'
                );
            }

            $undocumented = $take->lines()
                ->whereNotNull('variance_value')
                ->where('variance_value', '!=', 0)
                ->whereNull('reason_code')
                ->count();

            if ($undocumented > 0) {
                throw new DomainException(
                    "Stock take '{$take->reference}' has {$undocumented} variance line(s) without a reason code; document them before approval."
                );
            }

            $take->forceFill([
                'status' => StockTakeStatus::Approved,
                'approved_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                StockTake::class,
                (int) $take->getKey(),
                ['status' => 'counted'],
                ['status' => 'approved', 'approved_by' => $actor->id],
                $actor,
            );

            return $take;
        });
    }
}
