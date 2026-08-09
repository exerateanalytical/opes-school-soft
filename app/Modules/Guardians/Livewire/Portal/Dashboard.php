<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal` - the children dashboard, 07-students.md 7.5 row 1: "any valid
 * link" is the floor, so every child this guardian holds a currently-valid
 * `StudentGuardian` row for appears here, however narrow their other flags
 * are. Per-tile links are only rendered for the scopes the CHILD's link
 * actually grants (GuardianScopeMatrix, called per link, never assumed).
 */
#[Layout('layouts.portal')]
final class Dashboard extends Component
{
    public function render(): mixed
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $links = $context->validLinks();
        $studentIds = array_map(static fn ($link): int => $link->student_id, $links);

        $students = $studentIds === [] ? collect() : DB::table('students')
            ->whereIn('id', $studentIds)
            ->get(['id', 'first_name', 'last_name', 'matricule', 'photo_path'])
            ->keyBy('id');

        $classNames = $studentIds === [] ? collect() : DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereIn('enr.student_id', $studentIds)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->get(['enr.student_id', 'cg.name'])
            ->unique('student_id')
            ->keyBy('student_id');

        $children = [];

        foreach ($links as $link) {
            $row = $students->get($link->student_id);

            if ($row === null) {
                continue;
            }

            $flags = $link->authorizationFlags($context->asOf);

            $children[] = [
                'id' => $link->student_id,
                'name' => trim($row->first_name.' '.$row->last_name),
                'matricule' => $row->matricule,
                'relationship' => $link->relationship->value,
                'class' => optional($classNames->get($link->student_id))->name,
                'can_results' => $link->authorises(GuardianCapability::R05ViewReportCard->value, $context->asOf),
                'can_fees' => $link->authorises(GuardianCapability::R13ViewInvoices->value, $context->asOf)
                    || $link->authorises(GuardianCapability::R14ViewFeeStatement->value, $context->asOf)
                    || $link->authorises(GuardianCapability::R16ViewOwnPayments->value, $context->asOf),
                'can_discipline' => $link->authorises(GuardianCapability::R19ViewDisciplineList->value, $context->asOf),
                'can_documents' => $link->authorises(GuardianCapability::R22ViewSchoolIssuedDocuments->value, $context->asOf)
                    || $link->authorises(GuardianCapability::R23ViewGuardianSuppliedDocuments->value, $context->asOf),
            ];
        }

        return view('livewire.guardians.portal.dashboard', [
            'guardianName' => $context->guardian->fullName(),
            'children' => $children,
        ]);
    }
}
