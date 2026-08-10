<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\ApproveBudget;
use App\Modules\Accounting\Actions\SaveBudget;
use App\Modules\Accounting\Actions\SaveBudgetLine;
use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Domain\PhasingProfile;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * One realistic FY2026 operating budget, covering the accounts that actually
 * carry movement in the demo ledger, so the Budget vs Actual screen shows
 * real variances rather than an empty grid.
 *
 * ADDITIVE AND IDEMPOTENT, deliberately and strictly:
 *
 *  - it writes ONLY to `budgets`, `budget_lines` and `budget_phasings`;
 *  - it never touches a ledger row, and never touches a `chart_of_accounts`
 *    row (including `budget_control` - arming an account's over-budget
 *    control is a deliberate configuration decision an accountant makes on
 *    the Chart screen, not something a demo seeder does behind their back);
 *  - re-running it finds the existing budget by (fiscal year, code) and stops.
 *
 * The figures are chosen against the demo ledger as it stands: `706` is
 * over-earning, `6033` is close to plan, `661` is budgeted but barely spent
 * (the payroll demo posts to `422`), and `658` cash shortage is deliberately
 * budgeted at a token amount so the grid shows one small unfavourable line.
 */
final class BudgetDemoSeeder extends Seeder
{
    private const CODE = 'BUD-OPER';

    public function run(): void
    {
        $fiscalYear = FiscalYear::query()->where('code', '2026')->first();

        if ($fiscalYear === null) {
            $this->command->warn('No FY2026 fiscal year; nothing to budget against.');

            return;
        }

        $existing = Budget::query()
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->where('code', self::CODE)
            ->first();

        if ($existing !== null) {
            $this->command->info(sprintf(
                'Budget %s v%d already exists for FY%s (%s) - nothing to do.',
                self::CODE,
                (int) $existing->version,
                $fiscalYear->code,
                $existing->status->value,
            ));

            return;
        }

        $admin = \App\Modules\Identity\Models\User::query()->where('email', 'demo.admin@opeschool.test')->first()
            ?? \App\Modules\Identity\Models\User::query()->orderBy('id')->first();

        if ($admin === null) {
            $this->command->warn('No user to attribute the budget to; run DemoDataSeeder first.');

            return;
        }

        Auth::login($admin);
        $actor = new Actor((int) $admin->getKey(), (string) $admin->name);

        $budget = app(SaveBudget::class)->handle(
            budgetId: null,
            fiscalYearId: (int) $fiscalYear->getKey(),
            code: self::CODE,
            name: 'Operating budget FY2026',
            notes: 'Demo operating budget — seeded by BudgetDemoSeeder, additive only.',
            actor: $actor,
        );

        /** @var list<array{code: string, annual: int, profile: PhasingProfile, notes: string}> $lines */
        $lines = [
            [
                'code' => '706',
                'annual' => 40_000_000,
                'profile' => PhasingProfile::AcademicCalendar,
                'notes' => 'Tuition and service income — weighted to the September and January term openings.',
            ],
            [
                'code' => '661',
                'annual' => 18_000_000,
                'profile' => PhasingProfile::Equal,
                'notes' => 'Teaching and administrative payroll — flat across the year.',
            ],
            [
                'code' => '6033',
                'annual' => -600_000,
                'profile' => PhasingProfile::Equal,
                'notes' => 'Stock variation on other supplies — credit-normal, hence a negative budget.',
            ],
            [
                'code' => '658',
                'annual' => 24_000,
                'profile' => PhasingProfile::Equal,
                'notes' => 'Tolerated cash-desk shortage — 2 000 FCFA a month.',
            ],
        ];

        foreach ($lines as $line) {
            $account = ChartOfAccount::query()->where('code', $line['code'])->first();

            if ($account === null) {
                $this->command->warn(sprintf('Account %s is not in the chart; skipping that budget line.', $line['code']));

                continue;
            }

            app(SaveBudgetLine::class)->handle(
                budgetId: (int) $budget->getKey(),
                accountId: (int) $account->getKey(),
                analyticValueId: null,
                annualAmount: $line['annual'],
                profile: $line['profile'],
                manualAmounts: null,
                notes: $line['notes'],
                actor: $actor,
            );
        }

        app(ApproveBudget::class)->handle((int) $budget->getKey(), $actor, true);

        $this->command->info(sprintf(
            'Seeded budget %s v%d for FY%s (%s), %d lines.',
            self::CODE,
            (int) $budget->version,
            $fiscalYear->code,
            BudgetStatus::Approved->value,
            count($lines),
        ));
    }
}
