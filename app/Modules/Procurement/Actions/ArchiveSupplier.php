<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §3.1/§9 - the ONLY exit for a supplier
 * record: archive (with an optional block reason), never delete. The
 * archived row keeps its unique code and its 10-year AUDCIF history.
 */
final class ArchiveSupplier
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $supplierId, Actor $actor, ?string $reason = null): Supplier
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        return DB::transaction(function () use ($supplierId, $actor, $reason): Supplier {
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->whereKey($supplierId)->lockForUpdate()->firstOrFail();

            $supplier->is_archived = true;
            $supplier->is_active = false;

            if ($reason !== null && trim($reason) !== '') {
                $supplier->blocked_reason = trim($reason);
            }

            $supplier->updated_by = $actor->id;
            $supplier->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: Supplier::class,
                auditableId: (int) $supplier->getKey(),
                after: ['is_archived' => true, 'blocked_reason' => $supplier->blocked_reason],
                actor: $actor,
            );

            return $supplier;
        });
    }
}
