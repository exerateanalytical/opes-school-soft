<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\PhasingProfile;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 - add or amend one line of a DRAFT budget,
 * and (re)phase it in the same transaction.
 *
 * §16 constrains the target account: it "must be postable, class 6/7 or class
 * 2 for capex". That is a statement about the referenced `chart_of_accounts`
 * row, so it is asserted here, where the refusal can name the account, rather
 * than in a CHECK that could only say "constraint violated".
 *
 * The line and its phasing are written together because a line with no
 * phasing is invisible to the over-budget check (§16's control compares
 * against the YTD PHASED budget, not against a twelfth of the annual figure)
 * - shipping the two apart would leave a budget that silently enforces
 * nothing.
 */
final class SaveBudgetLine
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly ApplyBudgetPhasing $phasing,
    ) {
    }

    /**
     * @param  array<string, int>|null  $manualAmounts  'YYYY-MM-01' => amount,
     *                                                  required for
     *                                                  PhasingProfile::Manual.
     */
    public function handle(
        int $budgetId,
        int $accountId,
        ?int $analyticValueId,
        int $annualAmount,
        PhasingProfile $profile,
        ?array $manualAmounts,
        ?string $notes,
        Actor $actor,
    ): BudgetLine {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use (
            $budgetId,
            $accountId,
            $analyticValueId,
            $annualAmount,
            $profile,
            $manualAmounts,
            $notes,
            $actor,
        ): BudgetLine {
            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();

            SaveBudget::assertDraft($budget);

            $account = $this->assertBudgetableAccount($accountId);

            /** @var BudgetLine|null $existing */
            $existing = BudgetLine::query()
                ->where('budget_id', $budget->getKey())
                ->where('account_id', $account->getKey())
                ->where('analytic_key', $analyticValueId ?? 0)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $line = BudgetLine::query()->create([
                    'budget_id' => $budget->getKey(),
                    'account_id' => $account->getKey(),
                    'analytic_value_id' => $analyticValueId,
                    'annual_amount' => $annualAmount,
                    'notes' => $notes,
                ]);
                $auditAction = AuditAction::Created;
            } else {
                $existing->forceFill([
                    'annual_amount' => $annualAmount,
                    'notes' => $notes,
                ])->save();
                $line = $existing;
                $auditAction = AuditAction::Updated;
            }

            $this->phasing->handle((int) $line->getKey(), $profile, $manualAmounts, $actor);

            $this->audit->handle(
                action: $auditAction,
                module: 'Accounting',
                auditableType: BudgetLine::class,
                auditableId: (int) $line->getKey(),
                after: [
                    'budget_id' => (int) $budget->getKey(),
                    'account' => $account->code,
                    'analytic_value_id' => $analyticValueId,
                    'annual_amount' => $annualAmount,
                    'phasing' => $profile->value,
                ],
                actor: $actor,
            );

            return $line;
        });
    }

    public function delete(int $budgetLineId, Actor $actor): void
    {
        Gate::authorize(self::PERMISSION);

        DB::transaction(function () use ($budgetLineId, $actor): void {
            /** @var BudgetLine $line */
            $line = BudgetLine::query()->whereKey($budgetLineId)->lockForUpdate()->firstOrFail();

            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($line->budget_id)->lockForUpdate()->firstOrFail();

            SaveBudget::assertDraft($budget);

            // Phasing rows are RESTRICT-referenced by nothing else, and they
            // are meaningless without their line, so they go first and in the
            // same transaction.
            $line->phasings()->delete();
            $line->delete();

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Accounting',
                auditableType: BudgetLine::class,
                auditableId: $budgetLineId,
                after: ['budget_id' => (int) $budget->getKey()],
                actor: $actor,
            );
        });
    }

    private function assertBudgetableAccount(int $accountId): ChartOfAccount
    {
        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::query()->whereKey($accountId)->firstOrFail();

        if (! $account->is_postable) {
            throw new DomainException(sprintf(
                'Account %s (%s) is a heading, not a postable account; a budget line on it could never be compared against the ledger.',
                $account->code,
                $account->name,
            ));
        }

        if (! in_array($account->account_class, [2, 6, 7], true)) {
            throw new DomainException(sprintf(
                'Account %s (%s) is SYSCOHADA class %d. §16 budgets classes 6 and 7 (charges and produits) and class 2 for capex.',
                $account->code,
                $account->name,
                $account->account_class,
            ));
        }

        if ($account->is_archived) {
            throw new DomainException(sprintf('Account %s is archived and cannot be budgeted.', $account->code));
        }

        return $account;
    }
}
