<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/profile` - 07-students.md 7.5 rows 1-4, 31.
 *
 * Row 1 is the floor (identity always shown once the policy admits the
 * request at all); rows 2-4 and 31 are each independently gated because a
 * link can hold `is_emergency_contact` without `has_custody` (row 3 only) or
 * vice versa, and the matrix's per-row narrowing must survive being
 * assembled onto one screen.
 */
#[Layout('layouts.portal')]
final class ChildProfile extends Component
{
    public int $studentId;

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R01ViewChildIdentity, $student);

        $exists = DB::table('students')->where('id', $student)->exists();

        if (! $exists) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
    }

    public function render(): mixed
    {
        $policy = app(GuardianPortalPolicy::class);
        $context = PortalContext::current();

        $canDetail = $policy->allows(GuardianCapability::R02ViewChildProfileDetail, $this->studentId);
        $canEmergencyMedical = $policy->allows(GuardianCapability::R03ViewChildEmergencyMedical, $this->studentId);
        $canFullMedical = $policy->allows(GuardianCapability::R04ViewChildFullMedical, $this->studentId);
        $canOtherGuardians = $policy->allows(GuardianCapability::R31ViewOtherGuardiansOfChild, $this->studentId);

        $student = DB::table('students')->where('id', $this->studentId)->first([
            'first_name', 'last_name', 'matricule', 'admission_no', 'photo_path',
            'date_of_birth', 'gender', 'nationality', 'address_line', 'city', 'region',
            'blood_group', 'genotype',
        ]);

        $className = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $this->studentId)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        $emergencyMedical = collect();
        $fullMedical = collect();

        if (($canEmergencyMedical || $canFullMedical) && Schema::hasTable('student_medical_records')) {
            $query = DB::table('student_medical_records')->where('student_id', $this->studentId);

            if ($canFullMedical) {
                $fullMedical = (clone $query)->orderByDesc('recorded_at')
                    ->get(['condition_type', 'summary', 'detail', 'severity', 'is_emergency_relevant', 'recorded_at']);
            } elseif ($canEmergencyMedical) {
                $emergencyMedical = (clone $query)->where('is_emergency_relevant', true)
                    ->orderByDesc('recorded_at')
                    ->get(['condition_type', 'summary', 'severity', 'recorded_at']);
            }
        }

        $otherGuardians = collect();

        if ($canOtherGuardians && $context !== null) {
            $otherGuardians = DB::table('student_guardians as sg')
                ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
                ->where('sg.student_id', $this->studentId)
                ->where('sg.guardian_id', '!=', $context->guardian->getKey())
                ->where('sg.valid_from', '<=', $context->asOf)
                ->where(function (QueryBuilder $q) use ($context): void {
                    $q->whereNull('sg.valid_to')->orWhere('sg.valid_to', '>=', $context->asOf);
                })
                ->get(['g.first_name', 'g.last_name', 'sg.relationship']);
        }

        return view('livewire.guardians.portal.child-profile', [
            'studentId' => $this->studentId,
            'student' => $student,
            'className' => $className,
            'canDetail' => $canDetail,
            'canEmergencyMedical' => $canEmergencyMedical,
            'canFullMedical' => $canFullMedical,
            'canOtherGuardians' => $canOtherGuardians,
            'emergencyMedical' => $emergencyMedical,
            'fullMedical' => $fullMedical,
            'otherGuardians' => $otherGuardians,
        ]);
    }
}
