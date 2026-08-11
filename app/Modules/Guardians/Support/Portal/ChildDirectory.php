<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;

/**
 * The children a portal principal may see, and what they may see about each -
 * docs/specs/2026-08-11-guardian-mobile-api-v1.md §4.1.
 *
 * Row 1 of the 7.5 matrix is the floor: every currently-valid link puts its
 * child in this list, however narrow the link's other flags are. Everything
 * beyond identity is answered per capability by GuardianPortalPolicy, which
 * asks GuardianScopeMatrix - this class decides nothing itself, it only
 * assembles.
 *
 * `capabilities()` is the app's RENDERING contract: the mobile client hides a
 * tile whose code is absent. It is a convenience so a dashboard does not need
 * six round trips to know which tiles to draw - never a control. Every
 * endpoint re-checks the capability it needs, because a client that ignores
 * this list must still be refused by the server.
 *
 * Students are read through the query builder, not the Students module's
 * Eloquent model: 00-core §6.2 forbids reaching into another module's Models,
 * and the portal's Livewire screens read them the same way.
 */
final class ChildDirectory
{
    /**
     * The capability codes the mobile app is told about, in the order the
     * dashboard renders them. Deliberately a curated subset: the rows that
     * grant nobody anything (8, 25, 28, 30, 32) would only be noise, and the
     * write rows appear once their endpoints exist.
     *
     * @var list<GuardianCapability>
     */
    private const PROJECTED = [
        GuardianCapability::R01ViewChildIdentity,
        GuardianCapability::R02ViewChildProfileDetail,
        GuardianCapability::R03ViewChildEmergencyMedical,
        GuardianCapability::R04ViewChildFullMedical,
        GuardianCapability::R05ViewReportCard,
        GuardianCapability::R06DownloadReportCard,
        GuardianCapability::R07ViewPublishedMarks,
        GuardianCapability::R09ViewRankAndClassMean,
        GuardianCapability::R10ViewAnnualAverageAndPromotion,
        GuardianCapability::R11ViewAttendanceSummary,
        GuardianCapability::R12ViewAttendanceDetail,
        GuardianCapability::R13ViewInvoices,
        GuardianCapability::R14ViewFeeStatement,
        GuardianCapability::R15ViewReceipts,
        GuardianCapability::R18InitiatePayment,
        GuardianCapability::R19ViewDisciplineList,
        GuardianCapability::R20ViewDisciplineNarrative,
        GuardianCapability::R21AcknowledgeSanction,
        GuardianCapability::R22ViewSchoolIssuedDocuments,
        GuardianCapability::R23ViewGuardianSuppliedDocuments,
        GuardianCapability::R26ViewTimetableAndAnnouncements,
        GuardianCapability::R27RequestGuardianMeeting,
        GuardianCapability::R31ViewOtherGuardiansOfChild,
    ];

    public function __construct(private readonly GuardianPortalPolicy $policy)
    {
    }

    /**
     * Every child of this principal, identity plus capability projection.
     *
     * @return list<array<string, mixed>>
     */
    public function children(PortalContext $context): array
    {
        $links = $context->validLinks();
        $studentIds = array_map(static fn ($link): int => $link->student_id, $links);

        if ($studentIds === []) {
            return [];
        }

        $students = DB::table('students')
            ->whereIn('id', $studentIds)
            ->get(['id', 'first_name', 'last_name', 'matricule', 'photo_path', 'status'])
            ->keyBy('id');

        $classNames = $this->classNames($studentIds);

        $rows = [];

        foreach ($studentIds as $studentId) {
            $student = $students->get($studentId);

            if ($student === null) {
                // A link pointing at a deleted student. Skip rather than
                // present a half-row: the app has nothing to render for it.
                continue;
            }

            $rows[] = [
                'id' => $studentId,
                'matricule' => $student->matricule,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'display_name' => trim($student->first_name.' '.$student->last_name),
                'class' => $classNames[$studentId] ?? null,
                'status' => $student->status,
                'has_photo' => $student->photo_path !== null,
                'capabilities' => $this->capabilities($studentId),
            ];
        }

        return $rows;
    }

    /**
     * The capability codes granted for one child, in PROJECTED order.
     *
     * @return list<string>
     */
    public function capabilities(int $studentId): array
    {
        $granted = [];

        foreach (self::PROJECTED as $capability) {
            if ($this->policy->allows($capability, $studentId)) {
                $granted[] = $capability->value;
            }
        }

        return $granted;
    }

    /**
     * Current class group per student. The same shape the portal's Livewire
     * screens read: the open segment (`ends_on` null) of a live enrollment.
     *
     * @param  list<int>  $studentIds
     * @return array<int, string>
     */
    public function classNames(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $rows = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereIn('enr.student_id', $studentIds)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->get(['enr.student_id', 'cg.name']);

        $names = [];

        foreach ($rows as $row) {
            // orderByDesc + first-wins: the most recent open segment.
            $names[(int) $row->student_id] ??= (string) $row->name;
        }

        return $names;
    }
}
