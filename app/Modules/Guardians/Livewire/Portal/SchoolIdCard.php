<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{s}/id-card` - mobile/digital-school-id-child-id.png and
 * its `-secure` variant.
 *
 * Built entirely from row 1 (identity): name, class, matricule. That is all a
 * school ID carries and all every valid link is guaranteed to grant, so the
 * card opens for any linked parent at a school gate.
 *
 * The reveal step is the `-secure` design. It is a shoulder-surfing guard, not
 * authentication, and the copy says so: anyone holding this unlocked browser
 * can reach the same three facts through the child's profile. Presenting it as
 * a security control would misrepresent the threat model.
 *
 * The number shown is the MATRICULE, not a minted token. The platform verifies
 * documents by serial at a public page; inventing a second credential here
 * would be a second identity system nobody asked for.
 */
#[Layout('layouts.portal')]
final class SchoolIdCard extends Component
{
    public int $studentId;

    public string $childName = '';

    public string $matricule = '';

    public ?string $className = null;

    public bool $revealed = false;

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R01ViewChildIdentity, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name', 'matricule']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
        $this->matricule = (string) $row->matricule;

        $this->className = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $student)
            ->whereNull('seg.ends_on')
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.school-id-card');
    }
}
