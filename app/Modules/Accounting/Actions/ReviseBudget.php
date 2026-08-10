<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\BudgetPhasing;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 B-2 - "an approved budget is immutable;
 * changes produce version + 1".
 *
 * This is the ONLY way to change an approved budget: it deep-copies the lines
 * and their phasing into a new DRAFT at `version + 1`, leaving the approved
 * version exactly as it was signed off. The new version is not current and
 * enforces nothing until it is itself approved, at which point ApproveBudget
 * demotes the old one - so there is never a window where the ledger is
 * governed by two budgets or by none.
 */
final class ReviseBudget
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $budgetId, Actor $actor, ?string $notes = null): Budget
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($budgetId, $actor, $notes): Budget {
            /** @var Budget $source */
            $source = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();

            if ($source->status === BudgetStatus::Draft) {
                throw new DomainException(sprintf(
                    'Budget %s v%d is still a draft; edit it directly rather than versioning it.',
                    $source->code,
                    $source->version,
                ));
            }

            $existingDraft = Budget::query()
                ->where('fiscal_year_id', $source->fiscal_year_id)
                ->where('code', $source->code)
                ->where('status', BudgetStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($existingDraft !== null) {
                throw new DomainException(sprintf(
                    'Budget %s already has an open draft at version %d; finish or discard it before starting another revision.',
                    $source->code,
                    (int) $existingDraft->version,
                ));
            }

            $nextVersion = ((int) Budget::query()
                ->where('fiscal_year_id', $source->fiscal_year_id)
                ->where('code', $source->code)
                ->lockForUpdate()
                ->max('version')) + 1;

            $revision = Budget::query()->create([
                'fiscal_year_id' => $source->fiscal_year_id,
                'academic_year_id' => $source->academic_year_id,
                'code' => $source->code,
                'name' => $source->name,
                'status' => BudgetStatus::Draft,
                'version' => $nextVersion,
                'is_current' => false,
                'notes' => $notes ?? $source->notes,
            ]);

            /** @var list<BudgetLine> $lines */
            $lines = BudgetLine::query()->where('budget_id', $source->getKey())->get()->all();

            foreach ($lines as $line) {
                $copy = BudgetLine::query()->create([
                    'budget_id' => $revision->getKey(),
                    'account_id' => $line->account_id,
                    'analytic_value_id' => $line->analytic_value_id,
                    'annual_amount' => $line->annual_amount,
                    'notes' => $line->notes,
                ]);

                /** @var list<BudgetPhasing> $phasings */
                $phasings = BudgetPhasing::query()->where('budget_line_id', $line->getKey())->get()->all();

                foreach ($phasings as $phasing) {
                    BudgetPhasing::query()->create([
                        'budget_line_id' => $copy->getKey(),
                        'period_month' => $phasing->period_month->toDateString(),
                        'amount' => $phasing->amount,
                    ]);
                }
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: Budget::class,
                auditableId: (int) $revision->getKey(),
                after: [
                    'revises_budget_id' => (int) $source->getKey(),
                    'code' => $source->code,
                    'version' => $nextVersion,
                ],
                actor: $actor,
            );

            return $revision;
        });
    }
}
