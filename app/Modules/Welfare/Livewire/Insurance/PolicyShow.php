<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Insurance;

use App\Modules\Reporting\Support\PdfExport;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * One policy's file at /insurance/policies/{policy}, gated `insurance.view`
 * (Insurance\Index's own gate) - provider, coverage dates, every insured
 * student under it, and every claim against it, plus a printable
 * "Policy Summary" (Assets\Livewire\Show's asset-card pattern).
 *
 * Cross-module reads (student names, class levels) go through DB::table
 * joins only, never an Eloquent model reach across module boundaries
 * (ModuleBoundaryTest); every list is capped, no unbounded collection
 * reaches the view (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class PolicyShow extends Component
{
    /** Cap per list. */
    private const int HISTORY_LIMIT = 200;

    public int $policyId;

    public function mount(int $policy): void
    {
        Gate::authorize(InsurancePermission::VIEW);

        $this->policyId = $policy;

        // 404 early rather than rendering an empty file.
        DB::table('insurance_policies')->where('id', $policy)->firstOrFail();
    }

    private function policyRow(): stdClass
    {
        /** @var stdClass $row */
        $row = DB::table('insurance_policies as p')
            ->join('academic_years as y', 'y.id', '=', 'p.academic_year_id')
            ->leftJoin('fee_items as fi', 'fi.id', '=', 'p.fee_item_id')
            ->where('p.id', $this->policyId)
            ->select([
                'p.id', 'p.provider', 'p.policy_no', 'p.cover_type',
                'p.premium_per_student', 'p.coverage_start', 'p.coverage_end',
                'p.status', 'p.asset_id', 'p.created_at', 'p.updated_at',
                'y.name as year_name',
                'fi.id as fee_item_id', 'fi.code as fee_item_code', 'fi.name as fee_item_name',
                'fi.default_recurrence as fee_item_recurrence', 'fi.is_mandatory as fee_item_mandatory',
            ])
            ->first();

        return $row;
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function insuredStudents(): \Illuminate\Support\Collection
    {
        return DB::table('student_insurances as si')
            ->join('enrollments as e', 'e.id', '=', 'si.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
            ->where('si.policy_id', $this->policyId)
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'si.id', 'si.enrolled_on', 'si.certificate_no', 'si.status',
                's.first_name', 's.last_name', 's.matricule', 'cl.name as class_level',
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function claims(): \Illuminate\Support\Collection
    {
        return DB::table('insurance_claims as c')
            ->leftJoin('student_insurances as si', 'si.id', '=', 'c.student_insurance_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'si.enrollment_id')
            ->leftJoin('students as s', 's.id', '=', 'e.student_id')
            ->where('c.policy_id', $this->policyId)
            ->orderByDesc('c.incident_date')->orderByDesc('c.id')
            ->limit(self::HISTORY_LIMIT)
            ->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')
            ->select([
                'c.id', 'c.incident_date', 'c.description', 'c.amount_claimed',
                'c.amount_settled', 'c.status', 'c.settled_on',
                's.first_name', 's.last_name', 's.matricule',
                'si.certificate_no', 'u.name as recorded_by_name',
            ])
            ->get();
    }

    /**
     * Insured-student counts by status, straight from the database rather
     * than from the capped list above (a 300-student policy must still show
     * a truthful headline count).
     *
     * @return array<string, int>
     */
    private function insuredCounts(): array
    {
        /** @var \Illuminate\Support\Collection<int, stdClass> $rows */
        $rows = DB::table('student_insurances')
            ->where('policy_id', $this->policyId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->get();

        $counts = ['active' => 0, 'lapsed' => 0, 'cancelled' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
            $counts['total'] += (int) $row->total;
        }

        return $counts;
    }

    /**
     * Claim money and counts across the whole policy, uncapped.
     *
     * @return array<string, int>
     */
    private function claimTotals(): array
    {
        /** @var stdClass $row */
        $row = DB::table('insurance_claims')
            ->where('policy_id', $this->policyId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted")
            ->selectRaw("SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) as settled")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw('COALESCE(SUM(amount_claimed), 0) as claimed_amount')
            ->selectRaw('COALESCE(SUM(amount_settled), 0) as settled_amount')
            ->first();

        return [
            'total' => (int) $row->total,
            'submitted' => (int) $row->submitted,
            'settled' => (int) $row->settled,
            'rejected' => (int) $row->rejected,
            'claimed_amount' => (int) $row->claimed_amount,
            'settled_amount' => (int) $row->settled_amount,
        ];
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportPolicySummaryPdf(): Response
    {
        Gate::authorize(InsurancePermission::VIEW);

        $policy = $this->policyRow();

        return PdfExport::download(
            'Policy Summary — '.$policy->policy_no,
            ['Field', 'Value'],
            $this->policySummaryRows(),
            'policy-summary-'.$policy->policy_no.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function policySummaryRows(): iterable
    {
        $policy = $this->policyRow();

        yield ['Provider', $policy->provider];
        yield ['Policy No', $policy->policy_no];
        yield ['Cover Type', ucfirst($policy->cover_type)];
        yield ['Academic Year', $policy->year_name];
        yield ['Coverage Start', (string) $policy->coverage_start];
        yield ['Coverage End', (string) $policy->coverage_end];
        yield [
            'Premium Per Student',
            $policy->premium_per_student !== null ? Money::of((int) $policy->premium_per_student)->format(false) : '—',
        ];
        yield ['Status', ucfirst($policy->status)];
        yield ['Insured Students', (string) $this->insuredCounts()['total']];
        yield ['Claims', (string) $this->claimTotals()['total']];
        yield ['Billed As (Fee Item)', $policy->fee_item_code !== null ? $policy->fee_item_code.' — '.$policy->fee_item_name : '—'];
    }

    public function render(): mixed
    {
        $policy = $this->policyRow();
        $counts = $this->insuredCounts();
        $claimTotals = $this->claimTotals();

        $premium = $policy->premium_per_student !== null ? (int) $policy->premium_per_student : null;
        $end = \Illuminate\Support\Carbon::parse($policy->coverage_end);

        return view('livewire.welfare.insurance.policy-show', [
            'policy' => $policy,
            'insuredStudents' => $this->insuredStudents(),
            'claims' => $this->claims(),
            'counts' => $counts,
            'claimTotals' => $claimTotals,
            'premiumTotal' => $premium === null ? null : $premium * $counts['active'],
            'daysRemaining' => (int) round($end->diffInDays(now(), false) * -1),
        ]);
    }
}
