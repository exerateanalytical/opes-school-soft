<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 - create a budget, or edit one that is
 * still a draft.
 *
 * B-2 is the whole shape of this Action: an `approved` (or `closed`) budget
 * is immutable, and the way to change it is `ReviseBudget`, which produces
 * `version + 1`. So this Action refuses to touch anything that is not a
 * draft, under `FOR UPDATE` on the row so that an approval landing
 * concurrently cannot slip past the check.
 *
 * The academic-year axis is resolved the same way every financial entity
 * resolves it (§7 / C3): from the governing date, by query builder against
 * `academic_years`, never by importing Academics\Models\AcademicYear
 * (tests/Architecture/ModuleBoundaryTest.php forbids the import).
 */
final class SaveBudget
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        ?int $budgetId,
        int $fiscalYearId,
        string $code,
        string $name,
        ?string $notes,
        Actor $actor,
    ): Budget {
        Gate::authorize(self::PERMISSION);

        $code = trim($code);
        $name = trim($name);

        if ($code === '') {
            throw new DomainException('A budget needs a code; it is what the fiscal-year uniqueness key is built on.');
        }

        if ($name === '') {
            throw new DomainException('A budget needs a name.');
        }

        return DB::transaction(function () use ($budgetId, $fiscalYearId, $code, $name, $notes, $actor): Budget {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYearId)->firstOrFail();

            if ($budgetId === null) {
                $budget = Budget::query()->create([
                    'fiscal_year_id' => $fiscalYear->getKey(),
                    'academic_year_id' => AccountingPeriod::resolveAcademicYearId($fiscalYear->starts_on),
                    'code' => $code,
                    'name' => $name,
                    'status' => BudgetStatus::Draft,
                    'version' => $this->nextVersion($fiscalYear->getKey(), $code),
                    'is_current' => false,
                    'notes' => $notes,
                ]);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Accounting',
                    auditableType: Budget::class,
                    auditableId: (int) $budget->getKey(),
                    after: ['code' => $code, 'name' => $name, 'fiscal_year_id' => (int) $fiscalYear->getKey()],
                    actor: $actor,
                );

                return $budget;
            }

            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();

            $this->assertDraft($budget);

            $budget->forceFill([
                'code' => $code,
                'name' => $name,
                'notes' => $notes,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Budget::class,
                auditableId: (int) $budget->getKey(),
                after: ['code' => $code, 'name' => $name],
                actor: $actor,
            );

            return $budget;
        });
    }

    /**
     * B-2's guard, shared with the line/phasing Actions so that the refusal
     * message is identical wherever an operator hits it.
     */
    public static function assertDraft(Budget $budget): void
    {
        if ($budget->status->isEditable()) {
            return;
        }

        throw new DomainException(sprintf(
            'Budget %s v%d is %s and is immutable (§16 B-2). Revise it to create version %d instead of editing the figures that were signed off.',
            $budget->code,
            $budget->version,
            $budget->status->value,
            $budget->version + 1,
        ));
    }

    private function nextVersion(int $fiscalYearId, string $code): int
    {
        $max = Budget::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('code', $code)
            ->lockForUpdate()
            ->max('version');

        return $max === null ? 1 : ((int) $max) + 1;
    }
}
