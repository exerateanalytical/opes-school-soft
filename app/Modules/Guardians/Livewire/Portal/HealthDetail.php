<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDocuments;
use App\Modules\Guardians\Support\Portal\ChildMedical;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The health views beyond the overview:
 *
 *   history        mobile/medical-history.png       - row 4 only
 *   immunisations  mobile/immunization-vaccination-records.png
 *   documents      mobile/medical-documents.png     - row 23's shelf
 *   card           mobile/health-id.png + opes-health-id.png
 *
 * The scope split is the point. `history` and `immunisations` need row 4 (the
 * clinical record); the `card` deliberately needs only row 3, because an
 * emergency card that a non-custodial emergency contact could not open would
 * fail at exactly the moment it exists for.
 *
 * `detail` is never selected for a row-3 caller - ChildMedical filters the
 * COLUMN, not just the view, so the clinical note never reaches the wire.
 */
#[Layout('layouts.portal')]
final class HealthDetail extends Component
{
    public int $studentId;

    public string $childName = '';

    public string $view = 'history';

    public bool $canFull = false;

    /** The ID card starts covered - see the reveal note in the view. */
    public bool $revealed = false;

    public function mount(int $student, string $view = 'history'): void
    {
        $policy = app(GuardianPortalPolicy::class);
        $this->canFull = $policy->allows(GuardianCapability::R04ViewChildFullMedical, $student);
        $this->view = in_array($view, ['history', 'immunisations', 'documents', 'card'], true) ? $view : 'history';

        if ($this->view === 'documents') {
            $policy->authorize(GuardianCapability::R23ViewGuardianSuppliedDocuments, $student);
        } elseif ($this->view === 'card') {
            // Row 3 is enough, and that is deliberate.
            $policy->authorize(GuardianCapability::R03ViewChildEmergencyMedical, $student);
        } else {
            $policy->authorize(GuardianCapability::R04ViewChildFullMedical, $student);
        }

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name', 'matricule']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    public function render(): mixed
    {
        $records = app(ChildMedical::class)->records($this->studentId, $this->canFull);

        return view('livewire.guardians.portal.health-detail', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'view' => $this->view,
            'canFull' => $this->canFull,
            'revealed' => $this->revealed,
            'records' => $records,
            // Immunisations are part of the clinical record, so they are
            // filtered from the same row-4 set rather than fetched separately.
            'immunisations' => $records->filter(
                static fn (object $r): bool => str_contains(mb_strtolower((string) ($r->condition_type ?? '')), 'immun')
                    || str_contains(mb_strtolower((string) ($r->condition_type ?? '')), 'vaccin')
            )->values(),
            'documents' => $this->view === 'documents'
                ? app(ChildDocuments::class)->guardianSupplied($this->studentId)
                : collect(),
            'matricule' => DB::table('students')->where('id', $this->studentId)->value('matricule'),
        ]);
    }
}
