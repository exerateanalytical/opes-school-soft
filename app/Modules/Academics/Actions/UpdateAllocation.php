<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\SubjectAllocation;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateAllocation
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * Every successful update increments `version` (00-core 10.6): a Mark
     * entered under version N stays explicable after the allocation moves
     * to N+1.
     *
     * @param  array{coefficient?: string, subject_group_id?: int|null, required_components?: list<int>, max_score_override?: string|null, is_optional?: bool, counts_toward_average?: bool, effective_from_period_id?: int|null, effective_to_period_id?: int|null, is_active?: bool}  $changes
     */
    public function handle(SubjectAllocation $allocation, array $changes): SubjectAllocation
    {
        Gate::authorize(Permission::AcademicsManage->value);

        if (isset($changes['coefficient']) && (float) $changes['coefficient'] < 0.0) {
            throw new DomainException('A subject coefficient cannot be negative.');
        }

        return DB::transaction(function () use ($allocation, $changes): SubjectAllocation {
            $before = array_intersect_key($allocation->getOriginal(), $changes);

            $allocation->fill($changes);
            $after = $allocation->getDirty();
            $allocation->version = $allocation->version + 1;
            $allocation->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: SubjectAllocation::class,
                auditableId: (int) $allocation->getKey(),
                before: array_intersect_key($before, $after),
                after: $after,
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $allocation;
        });
    }
}
