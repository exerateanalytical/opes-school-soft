<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 - approve a draft budget and make it the
 * current one for its fiscal year (B-3), or close an approved one.
 *
 * Approval is what arms the over-budget control: `AssertWithinBudget` reads
 * ONLY the current approved budget, so a draft can never refuse a posting.
 *
 * B-3 is a database key (`uq_budgets_current_per_fy` on the generated
 * `current_fiscal_year_key`), so the demotion of the previous current budget
 * and the promotion of this one happen inside one transaction, previous
 * first. Two concurrent approvals cannot both win: the loser hits the unique
 * key.
 *
 * B-1 is re-checked across every line before approval, because that is the
 * moment the figures stop being editable and start refusing postings. A
 * budget whose phasing does not sum to its annual figures would enforce a
 * number nobody approved.
 */
final class ApproveBudget
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $budgetId, Actor $actor, bool $makeCurrent = true): Budget
    {
        Gate::authorize(self::PERMISSION);

        if ($actor->id === null) {
            throw new DomainException('A budget approval must name a user; `ck_budgets_approval` refuses an unattributed one.');
        }

        return DB::transaction(function () use ($budgetId, $actor, $makeCurrent): Budget {
            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();

            if ($budget->status !== BudgetStatus::Draft) {
                throw new DomainException(sprintf(
                    'Budget %s v%d is already %s.',
                    $budget->code,
                    $budget->version,
                    $budget->status->value,
                ));
            }

            $this->assertLinesPhased($budget);

            if ($makeCurrent) {
                Budget::query()
                    ->where('fiscal_year_id', $budget->fiscal_year_id)
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->get()
                    ->each(static function (Budget $previous): void {
                        $previous->forceFill(['is_current' => false])->save();
                    });
            }

            $budget->forceFill([
                'status' => BudgetStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'is_current' => $makeCurrent,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Budget::class,
                auditableId: (int) $budget->getKey(),
                after: ['status' => BudgetStatus::Approved->value, 'is_current' => $makeCurrent],
                actor: $actor,
            );

            return $budget;
        });
    }

    /** Lock an approved budget out of use without deleting the record of it. */
    public function close(int $budgetId, Actor $actor): Budget
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($budgetId, $actor): Budget {
            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();

            if ($budget->status !== BudgetStatus::Approved) {
                throw new DomainException(sprintf(
                    'Only an approved budget may be closed; %s v%d is %s.',
                    $budget->code,
                    $budget->version,
                    $budget->status->value,
                ));
            }

            $budget->forceFill([
                'status' => BudgetStatus::Closed,
                'is_current' => false,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Budget::class,
                auditableId: (int) $budget->getKey(),
                after: ['status' => BudgetStatus::Closed->value],
                actor: $actor,
            );

            return $budget;
        });
    }

    private function assertLinesPhased(Budget $budget): void
    {
        /** @var list<BudgetLine> $lines */
        $lines = BudgetLine::query()
            ->where('budget_id', $budget->getKey())
            ->withSum('phasings as phased_total', 'amount')
            ->get()
            ->all();

        if ($lines === []) {
            throw new DomainException(sprintf(
                'Budget %s v%d has no lines; approving it would arm an over-budget control with nothing in it.',
                $budget->code,
                $budget->version,
            ));
        }

        foreach ($lines as $line) {
            /** @var int|null $phased */
            $phased = $line->getAttribute('phased_total');

            if ((int) $phased !== (int) $line->annual_amount) {
                throw new DomainException(sprintf(
                    'Budget line %d is phased to %d but its annual amount is %d (§16 B-1). Re-phase it before approving.',
                    (int) $line->getKey(),
                    (int) $phased,
                    (int) $line->annual_amount,
                ));
            }
        }
    }
}
