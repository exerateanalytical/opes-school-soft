<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildAcademics;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\Portal\PublishedResults;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{s}/overview` - mobile/child-overview.png.
 *
 * The per-child hub: the same four headline figures the dashboard shows, but
 * for THIS child rather than the first one, over a tile grid of everywhere
 * they can go.
 *
 * The grid is driven entirely by the capabilities granted for this child. A
 * school that shares results but not fees produces a different hub from one
 * that shares both, and neither parent is offered a door that answers 403 -
 * which is the complaint this whole restyle started from.
 */
#[Layout('layouts.portal')]
final class ChildOverview extends Component
{
    public int $studentId;

    public string $childName = '';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R01ViewChildIdentity, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $policy = app(GuardianPortalPolicy::class);
        $tiles = [];

        if ($policy->allows(GuardianCapability::R05ViewReportCard, $this->studentId)) {
            $snapshot = app(PublishedResults::class)->snapshots($this->studentId)->first();
            $payload = $snapshot === null ? [] : app(PublishedResults::class)->payload($snapshot, false);

            // An array with a `display` key, not a scalar - the shape that
            // broke the dashboard the moment results existed.
            $average = is_array($payload['general_average'] ?? null)
                ? ($payload['general_average']['display'] ?? null)
                : null;

            if ($average !== null) {
                $tiles[] = ['label' => __('opes.guardian_portal.tile_average'), 'value' => (string) $average,
                    'icon' => 'chart', 'tone' => 'primary'];
            }
        }

        if ($policy->allows(GuardianCapability::R11ViewAttendanceSummary, $this->studentId)) {
            $summary = collect(app(ChildAcademics::class)->attendanceSummaries($this->studentId))->first();

            if ($summary !== null && (int) $summary->sessions_expected > 0) {
                $rate = (int) round(((int) $summary->sessions_present / (int) $summary->sessions_expected) * 100);
                $tiles[] = ['label' => __('opes.guardian_portal.tile_attendance'), 'value' => $rate.'%',
                    'icon' => 'calendar', 'tone' => $rate >= 90 ? 'success' : 'warning'];
            }
        }

        if ($policy->allows(GuardianCapability::R16ViewOwnPayments, $this->studentId)) {
            $fees = app(ChildFeeStatement::class);
            $enrollmentId = $fees->latestEnrollmentId($this->studentId);

            if ($enrollmentId !== null) {
                $totals = $fees->totals($enrollmentId);
                $tiles[] = ['label' => __('opes.guardian_portal.fees_balance'),
                    'value' => \App\Support\Money\Money::of($totals['outstanding'])->format(),
                    'icon' => 'wallet', 'tone' => $totals['outstanding'] > 0 ? 'danger' : 'success'];
            }
        }

        // Every destination this child's link actually opens.
        $links = collect([
            [GuardianCapability::R01ViewChildIdentity, 'portal.children.profile', __('opes.guardian_portal.tab_profile'), 'user'],
            [GuardianCapability::R05ViewReportCard, 'portal.children.results', __('opes.guardian_portal.tab_results'), 'book'],
            [GuardianCapability::R11ViewAttendanceSummary, 'portal.children.attendance', __('opes.guardian_portal.tab_attendance'), 'calendar'],
            [GuardianCapability::R26ViewTimetableAndAnnouncements, 'portal.children.timetable', __('opes.guardian_portal.tab_timetable'), 'clock'],
            [GuardianCapability::R16ViewOwnPayments, 'portal.children.fees', __('opes.guardian_portal.tab_fees'), 'card'],
            [GuardianCapability::R19ViewDisciplineList, 'portal.children.discipline', __('opes.guardian_portal.tab_discipline'), 'alert'],
            [GuardianCapability::R03ViewChildEmergencyMedical, 'portal.children.health', __('opes.guardian_portal.tab_health'), 'heart'],
            [GuardianCapability::R22ViewSchoolIssuedDocuments, 'portal.children.documents', __('opes.guardian_portal.tab_documents'), 'file'],
            [GuardianCapability::R01ViewChildIdentity, 'portal.children.id-card', __('opes.guardian_portal.id_card_title'), 'id'],
            [GuardianCapability::R31ViewOtherGuardiansOfChild, 'portal.children.contacts', __('opes.guardian_portal.contacts_title'), 'phone'],
        ])->filter(fn (array $l): bool => $policy->allows($l[0], $this->studentId))->values();

        return view('livewire.guardians.portal.child-overview', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'tiles' => $tiles,
            'links' => $links,
        ]);
    }
}
