<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §12.2 - create or reconfigure an analytic
 * DIMENSION. Axes shape what the analytic ledger IS, so this is gated on
 * `ledger.configure`, not `ledger.post` - the same split ConfigureJournal
 * draws.
 */
final class ConfigureAnalyticAxis
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{code?:string,name?:string,name_fr?:string,is_mandatory?:bool,applies_to_classes?:list<int>|null,requires_full_allocation?:bool,display_order?:int,is_active?:bool,is_archived?:bool}  $attributes
     */
    public function handle(?int $axisId, array $attributes, Actor $actor): AnalyticAxis
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($axisId, $attributes, $actor): AnalyticAxis {
            if ($axisId === null) {
                if (! isset($attributes['code'], $attributes['name'], $attributes['name_fr'])) {
                    throw new DomainException('A new analytic axis requires code, name and name_fr.');
                }

                $axis = AnalyticAxis::query()->create($attributes);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Accounting',
                    auditableType: AnalyticAxis::class,
                    auditableId: (int) $axis->getKey(),
                    after: $attributes,
                    actor: $actor,
                );

                return $axis->refresh();
            }

            /** @var AnalyticAxis $axis */
            $axis = AnalyticAxis::query()->lockForUpdate()->findOrFail($axisId);

            if (isset($attributes['code']) && $attributes['code'] !== $axis->code) {
                // The axis code is the stable key allocations and reports
                // hang off (AllocateLineAnalytics addresses by code) -
                // renaming it would silently re-aim history.
                throw new DomainException(sprintf('The code of axis %s is immutable.', $axis->code));
            }

            $before = $axis->only(array_keys($attributes));

            $axis->fill($attributes)->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: AnalyticAxis::class,
                auditableId: (int) $axis->getKey(),
                before: $before,
                after: $attributes,
                actor: $actor,
            );

            return $axis->refresh();
        });
    }
}
