<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 04-fees.md §2.1. Categories are pure reference data - the only invariant
 * is code uniqueness, which the schema enforces; this Action exists for the
 * permission gate and the audit row.
 */
final class CreateFeeCategory
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        string $code,
        string $name,
        string $nameFr,
        int $displayOrder = 0,
        ?Actor $actor = null,
    ): FeeCategory {
        Gate::authorize(Permission::FeeConfigure->value);

        if (trim($code) === '' || trim($name) === '' || trim($nameFr) === '') {
            throw new DomainException('A fee category requires a code and both language names.');
        }

        return DB::transaction(function () use ($code, $name, $nameFr, $displayOrder, $actor): FeeCategory {
            $category = FeeCategory::query()->create([
                'code' => $code,
                'name' => $name,
                'name_fr' => $nameFr,
                'display_order' => $displayOrder,
                'is_archived' => false,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: FeeCategory::class,
                auditableId: (int) $category->getKey(),
                after: ['code' => $code, 'name' => $name],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $category;
        });
    }
}
