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
            ->where('p.id', $this->policyId)
            ->select([
                'p.id', 'p.provider', 'p.policy_no', 'p.cover_type',
                'p.premium_per_student', 'p.coverage_start', 'p.coverage_end',
                'p.status', 'y.name as year_name',
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
            ->select([
                'c.id', 'c.incident_date', 'c.description', 'c.amount_claimed',
                'c.amount_settled', 'c.status', 'c.settled_on',
                's.first_name', 's.last_name',
            ])
            ->get();
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
        $insured = $this->insuredStudents();
        $claims = $this->claims();

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
        yield ['Insured Students', (string) $insured->count()];
        yield ['Claims', (string) $claims->count()];
    }

    public function render(): mixed
    {
        return view('livewire.welfare.insurance.policy-show', [
            'policy' => $this->policyRow(),
            'insuredStudents' => $this->insuredStudents(),
            'claims' => $this->claims(),
        ]);
    }
}
