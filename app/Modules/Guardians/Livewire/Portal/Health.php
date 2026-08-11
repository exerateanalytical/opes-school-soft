<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildMedical;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/health` - 07-students.md 7.5 rows 3 and 4.
 *
 * Two scopes that exist for a concrete reason: row 3 lets a school give a
 * non-custodial emergency contact the records that matter in an ambulance
 * without handing over the child's clinical history, which is row 4. The
 * narrower scope is not a degraded view - it is the whole, correct answer for
 * that guardian, so the screen states it rather than leaving a suspicious gap.
 *
 * Reading is delegated to ChildMedical, which applies BOTH narrowings (the
 * `is_emergency_relevant` row filter and the `detail` column filter) so the
 * clinical note never reaches an emergency-scope caller - not even to be
 * hidden in the view, which would still have put it on the wire.
 */
#[Layout('layouts.portal')]
final class Health extends Component
{
    public int $studentId;

    public string $childName = '';

    public bool $canFull = false;

    public function mount(int $student): void
    {
        $policy = app(GuardianPortalPolicy::class);

        $canEmergency = $policy->allows(GuardianCapability::R03ViewChildEmergencyMedical, $student);
        $this->canFull = $policy->allows(GuardianCapability::R04ViewChildFullMedical, $student);

        if (! $canEmergency && ! $this->canFull) {
            $policy->authorize(GuardianCapability::R03ViewChildEmergencyMedical, $student);
        }

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.health', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'canFull' => $this->canFull,
            'records' => app(ChildMedical::class)->records($this->studentId, $this->canFull),
        ]);
    }
}
