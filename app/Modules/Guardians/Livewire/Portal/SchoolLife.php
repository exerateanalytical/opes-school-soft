<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\GuardianInbox;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The guardian-scoped screens about the SCHOOL rather than one child:
 *
 *   activities   mobile/school-activities.png
 *   excursions   mobile/excursions-trips.png
 *   sports       mobile/sports-events.png
 *   detail       mobile/activity-details.png
 *   school       mobile/school-information.png
 *
 * Activities, excursions and sports have no endpoint and no matrix row - they
 * are a P0 non-goal. What the school genuinely broadcasts is ANNOUNCEMENTS, so
 * all three read those and each says so. Inventing a calendar the platform
 * cannot populate would be fiction dressed as a feature.
 *
 * Notably absent everywhere: a permission-slip button. Consent for an
 * excursion is a legal record and there is no write endpoint for one; a button
 * that appeared to grant it and posted nowhere would be the most dangerous
 * control in this portal.
 *
 * Row 26 gates all of it, and is granted on any valid link.
 */
#[Layout('layouts.portal')]
final class SchoolLife extends Component
{
    public string $view = 'activities';

    public ?int $activityId = null;

    public function mount(string $view = 'activities', ?int $activity = null): void
    {
        // `school` is public-facing information every portal principal may
        // see; the rest are row 26.
        if ($view !== 'school') {
            app(GuardianPortalPolicy::class)
                ->authorizeForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements);
        }

        $this->view = in_array($view, ['activities', 'excursions', 'sports', 'detail', 'school'], true)
            ? $view
            : 'activities';

        $this->activityId = $activity;
    }

    public function render(): mixed
    {
        $userId = (int) (auth()->id() ?? 0);
        $announcements = $this->view === 'school' ? collect() : app(GuardianInbox::class)->announcements($userId, 50);

        return view('livewire.guardians.portal.school-life', [
            'view' => $this->view,
            'announcements' => $announcements,
            'activity' => $this->activityId === null
                ? null
                : $announcements->firstWhere('id', $this->activityId),
            'schoolName' => __('opes.shell.brand'),
            'academicYear' => DB::table('academic_years')->orderByDesc('id')->value('name'),
        ]);
    }
}
