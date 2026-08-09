<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\MedicalPermission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W3): feeds the 09-ui §Medical dashboard cards
 * - Today's Visits · Active Treatments · Medical Records · Referrals - plus
 * the "Recent Medical Alerts by severity" rail.
 *
 * Counts only: no clinical narrative is decrypted here, and the
 * `student_medical_records` read is a cross-module DB::table COUNT, exactly
 * the boundary the plan draws (decrypted detail goes through the
 * Students\Actions\EmergencyMedicalSummary door instead).
 *
 * "Active treatments" = consultations from the last 7 days whose outcome
 * still has the child under care (sent home, referred or admitted - i.e.
 * anything but returned_to_class).
 */
final class MedicalDashboardStats
{
    /**
     * @return array{
     *     today_visits: int,
     *     active_treatments: int,
     *     medical_records: int,
     *     open_referrals: int,
     *     referrals_total: int,
     *     severity_breakdown: array<string, int>,
     * }
     */
    public function handle(): array
    {
        Gate::authorize(MedicalPermission::VIEW);

        $today = Carbon::today();

        $breakdown = [];

        foreach (ConsultationSeverity::cases() as $case) {
            $breakdown[$case->value] = 0;
        }

        $rows = DB::table('medical_consultations')
            ->where('visited_at', '>=', $today->copy()->subDays(30)->startOfDay())
            ->groupBy('severity')
            ->get([DB::raw('severity'), DB::raw('COUNT(*) as n')]);

        foreach ($rows as $row) {
            /** @var object{severity: string, n: int|string} $row */
            $breakdown[$row->severity] = (int) $row->n;
        }

        return [
            'today_visits' => (int) DB::table('medical_consultations')
                ->whereBetween('visited_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
                ->count(),
            'active_treatments' => (int) DB::table('medical_consultations')
                ->where('visited_at', '>=', $today->copy()->subDays(7)->startOfDay())
                ->where('outcome', '<>', ConsultationOutcome::ReturnedToClass->value)
                ->count(),
            'medical_records' => (int) DB::table('student_medical_records')->count(),
            'open_referrals' => (int) DB::table('medical_referrals')
                ->whereNull('followed_up_at')
                ->count(),
            'referrals_total' => (int) DB::table('medical_referrals')->count(),
            'severity_breakdown' => $breakdown,
        ];
    }
}
