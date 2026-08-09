<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/*
 * Shared fixture builders for the P11-F3 run-engine tests. All helper
 * names carry the p11run prefix (globally unique, function_exists-guarded).
 *
 * Every statutory VALUE below is a test fixture carrying the CLEISS 2024
 * reference figures (docs/specs/05-hr-payroll.md 2.3), tagged
 * @statutory-reference. None of them exists in any seeder or migration -
 * SeederRefusalTest asserts exactly that.
 */

if (! function_exists('p11runActor')) {
    /**
     * A logged-in user holding the named permissions (created on the fly -
     * the Phase 11 wiring package owns the seeder).
     *
     * @param  list<string>  $permissions
     */
    function p11runActor(array $permissions = []): User
    {
        $defaults = [
            'payroll.run', 'payroll.approve', 'payroll.reverse', 'payroll.configure',
            'ledger.post', 'ledger.configure',
        ];

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();

        foreach (array_values(array_unique([...$defaults, ...$permissions])) as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p11runCalendar')) {
    /**
     * Fiscal year 2031 (open) with open Jan-Mar periods, plus the academic
     * year that covers January 2031.
     */
    function p11runCalendar(): FiscalYear
    {
        /** @var FiscalYear $fiscal */
        $fiscal = FiscalYear::factory()->open()->create([
            'code' => '2031',
            'starts_on' => '2031-01-01',
            'ends_on' => '2031-12-31',
        ]);

        foreach (['2031-01-01', '2031-02-01', '2031-03-01'] as $month) {
            AccountingPeriod::factory()->create([
                'fiscal_year_id' => $fiscal->id,
                'period_month' => $month,
                'starts_on' => $month,
                'ends_on' => date('Y-m-t', strtotime($month)),
            ]);
        }

        AcademicYear::factory()->create([
            'code' => '2030-2031',
            'starts_on' => '2030-09-01',
            'ends_on' => '2031-08-31',
        ]);

        return $fiscal;
    }
}

if (! function_exists('p11runEmployerProfile')) {
    /**
     * The employer as its CNPS notification letter states it: regime
     * enseignement prive, risk class A.
     *
     * @param  array<string, mixed>  $attributes
     */
    function p11runEmployerProfile(array $attributes = []): EmployerProfile
    {
        return EmployerProfile::factory()->create($attributes);
    }
}

if (! function_exists('p11runReferenceRates')) {
    /**
     * @statutory-reference CLEISS 2024 (docs/specs/05-hr-payroll.md 2.3):
     * PVID 4.2 + 4.2 capped at 750,000 - PF 3.70 enseignement prive (7%
     * general kept alongside to prove N2 regime selection) - RP 1.75
     * class A UNCAPPED - IRPP annual brackets 10/15/25/35 - CAC 10% of
     * IRPP - CFC 1 / 1.5 - FNE 1 - RAV and TDL as zero-amount full-range
     * bands so a run can compute to the statutory-deduction stage.
     */
    function p11runReferenceRates(): void
    {
        $base = [
            'effective_from' => '2024-01-01',
            'is_verified' => true,
            'source_citation' => 'CLEISS 2024 reference values - @statutory-reference test fixture',
        ];

        StatutoryRate::factory()->create($base + [
            'code' => 'PVID', 'shape' => 'percentage', 'basis' => 'cnps_capped',
            'employee_rate_bp' => 4_200, 'employer_rate_bp' => 4_200, 'ceiling_amount' => 750_000,
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'PF', 'shape' => 'percentage', 'basis' => 'cnps_capped',
            'employer_rate_bp' => 3_700, 'cnps_regime' => 'enseignement_prive',
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'PF', 'shape' => 'percentage', 'basis' => 'cnps_capped',
            'employer_rate_bp' => 7_000, 'cnps_regime' => 'general',
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'RP', 'shape' => 'percentage', 'basis' => 'cnps_uncapped',
            'employer_rate_bp' => 1_750, 'risk_class' => 'A',
        ]);

        foreach ([
            [0, 2_000_000, 10_000],
            [2_000_000, 3_000_000, 15_000],
            [3_000_000, 5_000_000, 25_000],
            [5_000_000, null, 35_000],
        ] as [$from, $to, $rate]) {
            StatutoryRate::factory()->create($base + [
                'code' => 'IRPP', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
                'bracket_basis' => 'annual', 'band_from' => $from, 'band_to' => $to,
                'employee_rate_bp' => $rate,
            ]);
        }

        StatutoryRate::factory()->create($base + [
            'code' => 'CAC', 'shape' => 'percentage', 'basis' => 'irpp_amount',
            'employee_rate_bp' => 10_000,
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'CFC', 'shape' => 'percentage', 'basis' => 'gross',
            'employee_rate_bp' => 1_000, 'employer_rate_bp' => 1_500,
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'FNE', 'shape' => 'percentage', 'basis' => 'gross',
            'employer_rate_bp' => 1_000,
        ]);

        // Zero-amount full-range bands: the band TABLES are unverified
        // (2.4) and ship empty; these fixture bands exist so the engine
        // can be exercised to the net line. Zero is not a guess at the
        // real amounts - it is the identity for the test arithmetic.
        StatutoryRate::factory()->create($base + [
            'code' => 'RAV', 'shape' => 'flat_band', 'basis' => 'gross',
            'flat_amount' => 0, 'band_from' => 0, 'band_to' => null,
        ]);

        StatutoryRate::factory()->create($base + [
            'code' => 'TDL', 'shape' => 'flat_band', 'basis' => 'basic',
            'flat_amount' => 0, 'band_from' => 0, 'band_to' => null,
        ]);
    }
}

if (! function_exists('p11runAccounts')) {
    /**
     * Test ledger accounts (class-9 factory branch) mapped onto the
     * payroll components: one expense, one collective staff payable, one
     * liability per statutory component.
     *
     * @return array{expense: ChartOfAccount, payable: ChartOfAccount}
     */
    function p11runAccounts(): array
    {
        /** @var ChartOfAccount $expense */
        $expense = ChartOfAccount::factory()->create();

        /** @var ChartOfAccount $payable */
        $payable = ChartOfAccount::factory()->create([
            'is_collective' => true,
            'requires_partner' => true,
            'allowed_partner_types' => ['staff'],
        ]);

        foreach (PayrollComponent::query()->whereIn('type', ['employee_deduction', 'employer_charge'])->get() as $component) {
            /** @var ChartOfAccount $liability */
            $liability = ChartOfAccount::factory()->create();

            $component->forceFill(['liability_account_id' => $liability->id])->save();

            if ($component->type->value === 'employer_charge') {
                $component->forceFill(['expense_account_id' => $expense->id])->save();
            }
        }

        return ['expense' => $expense, 'payable' => $payable];
    }
}

if (! function_exists('p11runPostingRule')) {
    /**
     * The school-configured payroll.approved rule (8.7): Cr each class-4
     * remittance from the payload, Cr net per staff member on the
     * collective payable with the staff partner, balancing Dr on the
     * staff-cost expense account. Configuration, not seed data - exactly
     * how the customer's accountant will set it up.
     */
    function p11runPostingRule(User $accountant, ChartOfAccount $expense, ChartOfAccount $payable): void
    {
        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'PA')->firstOrFail();

        app(SavePostingRule::class)->handle([
            'code' => 'p11_payroll_approved',
            'event' => PostingEvent::PayrollApproved->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Paie {run.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Path,
                'account_path' => 'item.liability_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'iterates_over' => 'run.remittances',
                'skip_if_zero' => true,
                'label_expression' => 'Retenue {item.label} — {run.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => $payable->code,
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.net',
                'iterates_over' => 'run.items',
                'partner_source' => 'item.staff_partner',
                'skip_if_zero' => true,
                'label_expression' => 'Net à payer {item.label}',
            ],
            [
                'sequence' => 3,
                'account_source' => AccountSource::Literal,
                'account_code' => $expense->code,
                'sign' => LineSign::Debit,
                'amount_expression' => 'run.total_gross + run.total_employer_charges',
                'is_balancing' => true,
                'label_expression' => 'Charges de personnel {run.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('p11runStaff')) {
    /**
     * A CNPS-affiliated full-time staff member with a monthly BASIC.
     *
     * @return array{member: StaffMember, contract: StaffContract}
     */
    function p11runStaff(int $basicAmount, User $grantedBy, string $from = '2030-01-01'): array
    {
        /** @var StaffMember $member */
        $member = StaffMember::factory()->create();
        $member->forceFill(['cnps_number' => 'CNPS-'.$member->id])->save();

        /** @var StaffContract $contract */
        $contract = StaffContract::factory()->create([
            'staff_member_id' => $member->id,
            'starts_on' => $from,
            'seniority_reference_date' => $from,
        ]);

        DB::table('staff_compensations')->insert([
            'staff_contract_id' => $contract->id,
            'component_code' => 'BASIC',
            'amount' => $basicAmount,
            'rate_bp' => null,
            'effective_from' => $from,
            'effective_to' => null,
            'retroactive_from' => null,
            'granted_by' => $grantedBy->id,
            'grant_reason' => 'Hiring grant (test fixture)',
            'document_id' => null,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['member' => $member, 'contract' => $contract];
    }
}

if (! function_exists('p11runReady')) {
    /**
     * The full happy-path fixture: calendar, employer profile, reference
     * rates, accounts, posting rule, acting user with every permission.
     *
     * @return array{user: User, profile: EmployerProfile, expense: ChartOfAccount, payable: ChartOfAccount}
     */
    function p11runReady(): array
    {
        $user = p11runActor();
        p11runCalendar();
        $profile = p11runEmployerProfile();
        p11runReferenceRates();
        $accounts = p11runAccounts();
        p11runPostingRule($user, $accounts['expense'], $accounts['payable']);

        return [
            'user' => $user,
            'profile' => $profile,
            'expense' => $accounts['expense'],
            'payable' => $accounts['payable'],
        ];
    }
}
