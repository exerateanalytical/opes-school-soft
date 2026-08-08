<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\Accounting\Models\JournalEntryLineAnalytic;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §12.2 - create or reconfigure an analytic
 * MEMBER. Enforces:
 *
 * - UNIQUE(axis, code) surfaces as a clear domain error, not SQLSTATE 23000;
 * - a parent must live on the SAME axis (a SECTION member cannot hang under
 *   an ACTIVITY member - the hierarchy is per-dimension by definition);
 * - AN-4: a value referenced by any line whose fiscal year is not `closed`
 *   may not be archived. Archiving hides the value from new allocations;
 *   doing so while an open year still books against it would strand the
 *   year-end AnalyticGeneralReconciliation (§12.4) on an invisible member.
 */
final class ConfigureAnalyticValue
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{analytic_axis_id?:int,code?:string,name?:string,name_fr?:string,parent_id?:int|null,linked_type?:string|null,linked_id?:int|null,is_active?:bool,is_archived?:bool}  $attributes
     */
    public function handle(?int $valueId, array $attributes, Actor $actor): AnalyticValue
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($valueId, $attributes, $actor): AnalyticValue {
            if ($valueId === null) {
                return $this->create($attributes, $actor);
            }

            /** @var AnalyticValue $value */
            $value = AnalyticValue::query()->lockForUpdate()->findOrFail($valueId);

            if (isset($attributes['analytic_axis_id']) && $attributes['analytic_axis_id'] !== $value->analytic_axis_id) {
                throw new DomainException('An analytic value cannot move to another axis.');
            }

            if (array_key_exists('parent_id', $attributes) && $attributes['parent_id'] !== null) {
                $this->assertParentOnSameAxis($value->analytic_axis_id, $attributes['parent_id'], $valueId);
            }

            if (($attributes['is_archived'] ?? false) === true && ! $value->is_archived) {
                $this->assertNotReferencedByUnclosedYear($value);
            }

            $before = $value->only(array_keys($attributes));

            $value->fill($attributes)->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: AnalyticValue::class,
                auditableId: (int) $value->getKey(),
                before: $before,
                after: $attributes,
                actor: $actor,
            );

            return $value->refresh();
        });
    }

    /**
     * @param  array{analytic_axis_id?:int,code?:string,name?:string,name_fr?:string,parent_id?:int|null,linked_type?:string|null,linked_id?:int|null,is_active?:bool,is_archived?:bool}  $attributes
     */
    private function create(array $attributes, Actor $actor): AnalyticValue
    {
        if (! isset($attributes['analytic_axis_id'], $attributes['code'], $attributes['name'], $attributes['name_fr'])) {
            throw new DomainException('A new analytic value requires analytic_axis_id, code, name and name_fr.');
        }

        $axisId = $attributes['analytic_axis_id'];

        AnalyticAxis::query()->findOrFail($axisId);

        $exists = AnalyticValue::query()
            ->where('analytic_axis_id', $axisId)
            ->where('code', $attributes['code'])
            ->exists();

        if ($exists) {
            throw new DomainException(sprintf(
                'An analytic value with code %s already exists on this axis (UNIQUE(analytic_axis_id, code)).',
                $attributes['code'],
            ));
        }

        if (($attributes['parent_id'] ?? null) !== null) {
            $this->assertParentOnSameAxis($axisId, $attributes['parent_id'], null);
        }

        $value = AnalyticValue::query()->create($attributes);

        $this->audit->handle(
            action: AuditAction::Created,
            module: 'Accounting',
            auditableType: AnalyticValue::class,
            auditableId: (int) $value->getKey(),
            after: $attributes,
            actor: $actor,
        );

        return $value->refresh();
    }

    private function assertParentOnSameAxis(int $axisId, int $parentId, ?int $selfId): void
    {
        if ($selfId !== null && $parentId === $selfId) {
            throw new DomainException('An analytic value cannot be its own parent.');
        }

        /** @var AnalyticValue $parent */
        $parent = AnalyticValue::query()->findOrFail($parentId);

        if ($parent->analytic_axis_id !== $axisId) {
            throw new DomainException('An analytic value\'s parent must belong to the same axis.');
        }
    }

    /**
     * AN-4. "Referenced by an unclosed fiscal year" = at least one pivot row
     * whose line's entry sits in a fiscal year with status != closed.
     */
    private function assertNotReferencedByUnclosedYear(AnalyticValue $value): void
    {
        $referenced = JournalEntryLineAnalytic::query()
            ->where('analytic_value_id', $value->getKey())
            ->join('journal_entry_lines', 'journal_entry_lines.id', '=', 'journal_entry_line_analytics.journal_entry_line_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'journal_entries.fiscal_year_id')
            ->where('fiscal_years.status', '!=', FiscalYearStatus::Closed->value)
            ->exists();

        if ($referenced) {
            throw new DomainException(sprintf(
                'AN-4: analytic value %s is referenced by an unclosed fiscal year and cannot be archived.',
                $value->code,
            ));
        }
    }
}
