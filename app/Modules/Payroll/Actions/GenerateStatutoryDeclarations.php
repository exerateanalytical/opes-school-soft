<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Domain\DeclarationStatus;
use App\Modules\Payroll\Domain\DeclarationType;
use App\Modules\Payroll\Models\StatutoryDeclaration;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The compliance-calendar materialiser (docs/specs/05-hr-payroll.md 11.2,
 * 11.3): one declaration row per return per closed payroll month, plus the
 * annual returns in December and a per-person `staff_departure` row for
 * every worker flagged departed (11.5).
 *
 * Deadlines: DIPE, the CNPS contribution schedule and the DGI monthly
 * salary return fall due on the 15TH OF THE FOLLOWING MONTH. The TDL
 * remittance (per-commune schedule), the annual returns and the departure
 * filing are NEEDS VERIFICATION: their due_date stays NULL and the screen
 * shows "Deadline not configured" - a fabricated deadline is worse than
 * none.
 *
 * Scheduled job semantics: idempotent (the dedupe UNIQUE), system-actored,
 * no permission gate - filing itself (not generation) is what
 * `declaration.file` guards.
 */
final class GenerateStatutoryDeclarations
{
    /**
     * @return int number of declaration rows materialised
     */
    public function handle(string $payrollMonth, ?Actor $actor = null): int
    {
        $month = Carbon::parse($payrollMonth)->startOfMonth();
        $dueOn15th = $month->copy()->addMonth()->day(15)->toDateString();

        return DB::transaction(function () use ($month, $dueOn15th): int {
            $runIds = [];

            foreach (
                DB::table('payroll_runs')
                    ->where('payroll_month', $month->toDateString())
                    ->whereIn('status', ['approved', 'paid', 'closed'])
                    ->pluck('id') as $runId
            ) {
                $runIds[] = is_string($runId) ? $runId : (int) $runId;
            }

            $written = 0;

            $monthly = [
                [DeclarationType::Dipe, 'CNPS', $dueOn15th],
                [DeclarationType::CnpsContributionSchedule, 'CNPS', $dueOn15th],
                [DeclarationType::DgiMonthlySalaryReturn, 'DGI', $dueOn15th],
                [DeclarationType::TdlRemittance, 'Commune', null],
            ];

            foreach ($monthly as [$type, $payee, $due]) {
                $written += $this->materialise(
                    type: $type,
                    payee: $payee,
                    periodMonth: $month->toDateString(),
                    periodYear: null,
                    staffMemberId: null,
                    dueDate: $due,
                    runIds: $runIds,
                );
            }

            // December closes the year: the annual reconciliations fall due
            // (dates unverified => NULL).
            if ($month->month === 12) {
                $written += $this->materialise(DeclarationType::AnnualSalaryReturn, 'DGI', null, $month->year, null, null, $runIds);
                $written += $this->materialise(DeclarationType::CnpsAnnual, 'CNPS', null, $month->year, null, null, $runIds);
            }

            // 11.5: departures flagged by TerminateContract get their CNPS
            // filing row. Cross-module READ via DB::table (00-core 6.2).
            $departedIds = DB::table('staff_members')
                ->where('cnps_registration_status', 'declared_departed')
                ->pluck('id')
                ->all();

            foreach ($departedIds as $staffMemberId) {
                $alreadyFiled = StatutoryDeclaration::query()
                    ->where('type', DeclarationType::StaffDeparture->value)
                    ->where('staff_member_id', $staffMemberId)
                    ->exists();

                if (! $alreadyFiled) {
                    $written += $this->materialise(
                        DeclarationType::StaffDeparture, 'CNPS',
                        $month->toDateString(), null, (int) $staffMemberId, null, [],
                    );
                }
            }

            // 11.3: unfiled rows past their deadline surface as `late` - the
            // warning banner's source. Filed/paid rows are left alone.
            StatutoryDeclaration::query()
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->whereIn('status', [DeclarationStatus::NotDue->value, DeclarationStatus::Due->value, DeclarationStatus::Generated->value])
                ->update(['status' => DeclarationStatus::Late->value]);

            return $written;
        });
    }

    /**
     * @param  list<int|string>  $runIds
     */
    private function materialise(
        DeclarationType $type,
        string $payee,
        ?string $periodMonth,
        ?int $periodYear,
        ?int $staffMemberId,
        ?string $dueDate,
        array $runIds,
    ): int {
        // insertOrIgnore on the dedupe UNIQUE: generation is idempotent.
        return DB::table('statutory_declarations')->insertOrIgnore([
            'type' => $type->value,
            'payee' => $payee,
            'period_month' => $periodMonth,
            'period_year' => $periodYear,
            'staff_member_id' => $staffMemberId,
            'due_date' => $dueDate,
            'status' => DeclarationStatus::Due->value,
            'generated_at' => now(),
            'penalty_amount' => 0,
            'generated_from_run_ids' => $runIds === [] ? null : json_encode($runIds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
