<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDirectory;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One child (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4, rows 4, 18,
 * 19) - the API counterpart of Livewire\Portal\ChildProfile, gating the same
 * 7.5 rows: 1 (identity floor), 2 (profile detail), 3 vs 4 (emergency-only
 * medical vs the whole record), 31 (other guardians, names and relationship
 * only).
 *
 * 404, never 403, when the capability is missing for a child-scoped read: row
 * 32 makes the EXISTENCE of a child a guarded fact, and a 403 would confirm it.
 * The one exception is a sibling capability on a child they demonstrably do
 * hold a link to - there, the app asked for something its own capability list
 * said it could not have, and 403 is the honest answer.
 */
final class ChildrenController
{
    public function __construct(
        private readonly ChildDirectory $directory,
        private readonly GuardianPortalPolicy $policy,
    ) {
    }

    /** `GET /v1/me/children/{student}` */
    public function show(int $student): JsonResponse
    {
        $context = $this->context();
        $this->requireLink($student);

        $row = DB::table('students')->where('id', $student)->first([
            'id', 'first_name', 'last_name', 'matricule', 'admission_no', 'photo_path',
            'date_of_birth', 'gender', 'nationality', 'address_line', 'city', 'region',
            'status',
        ]);

        if ($row === null) {
            abort(404);
        }

        $canDetail = $this->policy->allows(GuardianCapability::R02ViewChildProfileDetail, $student);
        $classNames = $this->directory->classNames([$student]);

        // Row 1 is identity only. The detail block is row 2, and a link with
        // is_emergency_contact but no has_custody legitimately sees the first
        // and not the second.
        $data = [
            'id' => (int) $row->id,
            'matricule' => $row->matricule,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'display_name' => trim($row->first_name.' '.$row->last_name),
            'class' => $classNames[$student] ?? null,
            'status' => $row->status,
            'has_photo' => $row->photo_path !== null,
            'capabilities' => $this->directory->capabilities($student),
        ];

        if ($canDetail) {
            $data['detail'] = [
                'admission_no' => $row->admission_no,
                'date_of_birth' => $row->date_of_birth,
                'gender' => $row->gender,
                'nationality' => $row->nationality,
                'address_line' => $row->address_line,
                'city' => $row->city,
                'region' => $row->region,
            ];
        }

        unset($context);

        return response()->json(['data' => $data]);
    }

    /**
     * `GET /v1/me/children/{student}/guardians` - row 31.
     *
     * Names and relationship ONLY. The matrix comment is explicit that the
     * narrowing is the query's job: another guardian's ID number, address and
     * phone are not this guardian's business even when they may know the
     * person exists.
     */
    public function guardians(int $student): JsonResponse
    {
        $context = $this->context();
        $this->requireLink($student);

        if (! $this->policy->allows(GuardianCapability::R31ViewOtherGuardiansOfChild, $student)) {
            abort(403);
        }

        $rows = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', $student)
            ->where('sg.guardian_id', '!=', $context->guardian->getKey())
            ->where('sg.valid_from', '<=', $context->asOf)
            ->where(function (QueryBuilder $query) use ($context): void {
                $query->whereNull('sg.valid_to')->orWhere('sg.valid_to', '>=', $context->asOf);
            })
            ->get(['g.first_name', 'g.last_name', 'sg.relationship']);

        return response()->json([
            'data' => $rows->map(static fn ($row): array => [
                'display_name' => trim($row->first_name.' '.$row->last_name),
                'relationship' => $row->relationship,
            ])->all(),
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/medical` - rows 3 and 4.
     *
     * Two different answers behind one route, and the difference is the whole
     * point of rows 3 and 4 being separate rows: an emergency contact sees the
     * emergency-relevant records at the moment they are needed, a custodial
     * guardian sees the record. A caller holding both gets the full view.
     */
    public function medical(int $student): JsonResponse
    {
        $this->requireLink($student);

        $canFull = $this->policy->allows(GuardianCapability::R04ViewChildFullMedical, $student);
        $canEmergency = $this->policy->allows(GuardianCapability::R03ViewChildEmergencyMedical, $student);

        if (! $canFull && ! $canEmergency) {
            abort(403);
        }

        if (! Schema::hasTable('student_medical_records')) {
            return response()->json(['data' => ['scope' => $canFull ? 'full' : 'emergency', 'records' => []]]);
        }

        $query = DB::table('student_medical_records')
            ->where('student_id', $student)
            ->orderByDesc('recorded_at');

        if ($canFull) {
            $records = $query->get([
                'condition_type', 'summary', 'detail', 'severity', 'is_emergency_relevant', 'recorded_at',
            ]);
        } else {
            $records = $query->where('is_emergency_relevant', true)
                ->get(['condition_type', 'summary', 'severity', 'recorded_at']);
        }

        return response()->json([
            'data' => [
                'scope' => $canFull ? 'full' : 'emergency',
                'records' => $records->all(),
            ],
        ]);
    }

    /**
     * A currently-valid link, or 404. Row 32: for a child they are not linked
     * to, nothing - including whether that child exists at all.
     */
    private function requireLink(int $student): void
    {
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return $context;
    }
}
