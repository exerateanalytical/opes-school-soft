<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\SupplierCategory;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §3.4 - create or update a supplier
 * category. Reference data with archive-flag deletion: `is_active => false`
 * is the only way out, the unique code is never recycled.
 */
final class SaveSupplierCategory
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{code?: string, name?: string, name_fr?: string|null, default_expense_account_id?: int|null, default_tax_code_id?: int|null, default_withholding_profile_id?: int|null, is_active?: bool}  $payload
     */
    public function handle(array $payload, Actor $actor, ?int $categoryId = null): SupplierCategory
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        return DB::transaction(function () use ($payload, $actor, $categoryId): SupplierCategory {
            if ($categoryId === null) {
                $code = trim((string) ($payload['code'] ?? ''));
                $name = trim((string) ($payload['name'] ?? ''));

                if ($code === '' || $name === '') {
                    throw ValidationException::withMessages([
                        'code' => 'A supplier category needs a code and a name.',
                    ]);
                }

                /** @var SupplierCategory $category */
                $category = SupplierCategory::query()->create($payload);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Procurement',
                    auditableType: SupplierCategory::class,
                    auditableId: (int) $category->getKey(),
                    after: ['code' => $category->code, 'name' => $category->name],
                    actor: $actor,
                );

                return $category;
            }

            /** @var SupplierCategory $category */
            $category = SupplierCategory::query()->findOrFail($categoryId);

            // The code is the stable identity reports and imports key on.
            unset($payload['code']);

            $before = ['name' => $category->name, 'is_active' => $category->is_active];
            $category->fill($payload);
            $category->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierCategory::class,
                auditableId: (int) $category->getKey(),
                before: $before,
                after: ['name' => $category->name, 'is_active' => $category->is_active],
                actor: $actor,
            );

            return $category;
        });
    }
}
