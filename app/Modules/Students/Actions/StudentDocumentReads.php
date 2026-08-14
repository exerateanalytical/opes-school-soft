<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §7 - the reads every student-document Print
 * Action shares: the student's plain identity row, the enrollment context
 * (year, level, section, current/last class group), and the discipline
 * summary the conduct documents print.
 *
 * Query-builder only for the cross-module tables (academic_years,
 * class_levels, class_groups, school_sections, discipline_*,
 * attendance_*) - tests/Architecture/ModuleBoundaryTest.php forbids this
 * module from importing another module's Models, and DB::table is the
 * sanctioned way round (the same choice Fees\Actions\PrintReceipt and
 * Students\Livewire\Students\Show already make).
 */
final class StudentDocumentReads
{
    /**
     * @return object{id: int, matricule: string, admission_no: string, first_name: string,
     *     middle_name: string|null, last_name: string, date_of_birth: string,
     *     place_of_birth: string|null, gender: string, nationality: string,
     *     first_admission_date: string|null, left_on: string|null, status: string,
     *     phone: string|null, email: string|null, address_line: string|null,
     *     city: string|null, region: string|null}
     */
    public function student(int $studentId): object
    {
        /** @var object{id: int, matricule: string, admission_no: string, first_name: string, middle_name: string|null, last_name: string, date_of_birth: string, place_of_birth: string|null, gender: string, nationality: string, first_admission_date: string|null, left_on: string|null, status: string, phone: string|null, email: string|null, address_line: string|null, city: string|null, region: string|null}|null $student */
        $student = DB::table('students')->where('id', $studentId)->first([
            'id', 'matricule', 'admission_no', 'first_name', 'middle_name', 'last_name',
            'date_of_birth', 'place_of_birth', 'gender', 'nationality',
            'first_admission_date', 'left_on', 'status', 'phone', 'email',
            'address_line', 'city', 'region',
        ]);

        if ($student === null) {
            throw new DomainException("Student {$studentId} does not exist.");
        }

        return $student;
    }

    public function fullName(object $student): string
    {
        /** @var object{first_name: string, middle_name: string|null, last_name: string} $student */
        return trim(implode(' ', array_filter([
            $student->first_name,
            $student->middle_name,
            $student->last_name,
        ])));
    }

    /**
     * The enrollment a certificate speaks about: the student's most recent
     * one (live first, else latest by enrolled_on), joined to the names the
     * document prints. Certificates about a DEPARTED student (Transfer /
     * Leaving) naturally land on the terminal enrollment this returns.
     *
     * @return object{id: int, student_id: int, academic_year_id: int, status: string,
     *     enrolled_on: string, left_on: string|null, financial_clearance: int|bool,
     *     previous_school_name: string|null, school_section_id: int,
     *     year_name: string, level_name: string, section_name: string,
     *     section_name_fr: string}
     */
    public function latestEnrollment(int $studentId, bool $requireLive = false): object
    {
        $query = DB::table('enrollments as enr')
            ->join('academic_years as ay', 'ay.id', '=', 'enr.academic_year_id')
            ->join('class_levels as cl', 'cl.id', '=', 'enr.class_level_id')
            ->join('school_sections as ss', 'ss.id', '=', 'enr.school_section_id')
            ->where('enr.student_id', $studentId);

        if ($requireLive) {
            $query->whereIn('enr.status', ['pending', 'active', 'suspended']);
        }

        /** @var object{id: int, student_id: int, academic_year_id: int, status: string, enrolled_on: string, left_on: string|null, financial_clearance: int|bool, previous_school_name: string|null, school_section_id: int, year_name: string, level_name: string, section_name: string, section_name_fr: string}|null $enrollment */
        $enrollment = $query
            ->orderByDesc('enr.enrolled_on')
            ->orderByDesc('enr.id')
            ->first([
                'enr.id', 'enr.student_id', 'enr.academic_year_id', 'enr.status',
                'enr.enrolled_on', 'enr.left_on', 'enr.financial_clearance',
                'enr.previous_school_name', 'enr.school_section_id',
                'ay.name as year_name', 'cl.name as level_name',
                'ss.name as section_name', 'ss.name_fr as section_name_fr',
            ]);

        if ($enrollment === null) {
            throw new DomainException(
                $requireLive
                    ? "Student {$studentId} has no live enrollment; this document attests a current registration."
                    : "Student {$studentId} has never been enrolled; there is no enrollment to document."
            );
        }

        return $enrollment;
    }

    /**
     * The class group of the enrollment's LAST segment - the open one where
     * one exists, else the latest closed one ("class at departure").
     */
    public function lastClassGroupName(int $enrollmentId): string
    {
        $name = DB::table('enrollment_segments as seg')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('seg.enrollment_id', $enrollmentId)
            ->orderByRaw('(seg.ends_on IS NULL) DESC')
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        return is_string($name) ? $name : '';
    }

    /**
     * The §4.7 subject_identity block, assembled once so all eight documents
     * present the same identity strip.
     *
     * @return array<string, string>
     */
    public function identityBlock(object $student, ?object $enrollment, string $classGroup): array
    {
        /** @var object{matricule: string, date_of_birth: string} $student */
        /** @var object{year_name: string, section_name: string}|null $enrollment */
        return [
            'name' => $this->fullName($student),
            'matricule' => $student->matricule,
            'class_group' => $classGroup,
            'section' => $enrollment->section_name ?? '',
            'academic_year' => $enrollment->year_name ?? '',
            'date_of_birth' => Carbon::parse($student->date_of_birth)->format('d/m/Y'),
        ];
    }

    /**
     * Conduct summary for the conduct-bearing documents (§7.6, §7.8, §7.9):
     * counts of NON-positive discipline cases, split into open and total,
     * plus the highest open severity.
     *
     * @return array{total_cases: int, open_cases: int, max_open_severity: int}
     */
    public function disciplineSummary(int $studentId): array
    {
        $total = (int) DB::table('discipline_cases')
            ->where('student_id', $studentId)
            ->where('is_positive', false)
            ->count();

        $open = DB::table('discipline_cases as dc')
            ->join('discipline_categories as cat', 'cat.id', '=', 'dc.discipline_category_id')
            ->where('dc.student_id', $studentId)
            ->where('dc.is_positive', false)
            ->whereIn('dc.status', ['open', 'under_investigation'])
            ->get(['cat.severity']);

        $maxSeverity = 0;

        foreach ($open as $case) {
            /** @var object{severity: int|string} $case */
            $maxSeverity = max($maxSeverity, (int) $case->severity);
        }

        return [
            'total_cases' => $total,
            'open_cases' => $open->count(),
            'max_open_severity' => $maxSeverity,
        ];
    }

    /**
     * §7.11's computation, on §7 C5's real denominator: registers actually
     * taken for the class groups this enrollment occupied on each date.
     * `late` re-adds as present per 07-students §9.6; every other exception
     * status is an absence for the rate.
     *
     * @return array{registers: int, absences: int, present: int, rate_percent: float}
     */
    public function attendanceInRange(int $enrollmentId, string $from, string $to): array
    {
        // Registers whose date falls inside BOTH the requested range and a
        // segment of this enrollment covering that date - a mid-year class
        // transfer keeps both halves of the history (07-students C2).
        $registers = (int) DB::table('attendance_registers as reg')
            ->join('enrollment_segments as seg', function ($join): void {
                $join->on('seg.class_group_id', '=', 'reg.class_group_id')
                    ->whereColumn('seg.starts_on', '<=', 'reg.date')
                    ->where(function ($q): void {
                        $q->whereNull('seg.ends_on')->orWhereColumn('seg.ends_on', '>=', 'reg.date');
                    });
            })
            ->where('seg.enrollment_id', $enrollmentId)
            ->whereBetween('reg.date', [$from, $to])
            ->count();

        $absences = (int) DB::table('attendance_records as rec')
            ->join('attendance_registers as reg', 'reg.id', '=', 'rec.attendance_register_id')
            ->where('rec.enrollment_id', $enrollmentId)
            ->whereBetween('reg.date', [$from, $to])
            ->whereIn('rec.status', ['absent', 'excused', 'sick', 'suspended'])
            ->count();

        $present = max(0, $registers - $absences);

        return [
            'registers' => $registers,
            'absences' => $absences,
            'present' => $present,
            'rate_percent' => $registers > 0 ? round($present * 100 / $registers, 1) : 0.0,
        ];
    }
}
