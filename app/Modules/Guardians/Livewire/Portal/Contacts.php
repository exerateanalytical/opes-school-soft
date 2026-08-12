<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{s}/contacts` - mobile/emergency-important-contacts.png
 * and teacher-school-contact.png.
 *
 * Row 31, and the narrowing is the whole point: the school shares the other
 * guardians' NAMES AND RELATIONSHIP ONLY. No phone, no email, no ID number. A
 * parent is entitled to know who else is on their child's record; they are not
 * entitled to a directory of the other family, which in a separated household
 * is precisely the disclosure that matters.
 *
 * So this screen offers no call button for another guardian - there would be
 * nothing to dial, and rendering a disabled one would imply the number is
 * being withheld rather than never sent.
 *
 * The SCHOOL's own numbers are dialable, because those are public.
 */
#[Layout('layouts.portal')]
final class Contacts extends Component
{
    public int $studentId;

    public string $childName = '';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)
            ->authorize(GuardianCapability::R31ViewOtherGuardiansOfChild, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $context = PortalContext::current();

        // Names and relationship. The select list IS the control - a column
        // never fetched cannot leak through a view that forgets to hide it.
        $others = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', $this->studentId)
            ->when($context !== null, fn ($q) => $q->where('sg.guardian_id', '!=', $context->guardian->getKey()))
            ->where('g.is_archived', false)
            ->get(['g.first_name', 'g.last_name', 'sg.relationship', 'sg.is_emergency_contact']);

        return view('livewire.guardians.portal.contacts', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'others' => $others,
            'canMeet' => app(GuardianPortalPolicy::class)
                ->allows(GuardianCapability::R27RequestGuardianMeeting, $this->studentId),
        ]);
    }
}
