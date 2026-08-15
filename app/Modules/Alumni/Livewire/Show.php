<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Livewire;

use App\Modules\Alumni\Actions\MarkDeceased;
use App\Modules\Alumni\Actions\RecordEngagement;
use App\Modules\Alumni\Actions\UpdateAlumnusContact;
use App\Modules\Alumni\Domain\EngagementType;
use App\Modules\Alumni\Models\AlumniEngagement;
use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One alumnus's file, gated `alumni.view`: the profile card (the frozen
 * graduation facts plus the live contact half), the engagement timeline,
 * and - for `alumni.manage` - the record-engagement form, the contact
 * form and the one-way deceased action in the rail.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $alumnusId;

    // ── Record engagement form ──────────────────────────────────────────
    public string $engagementType = 'visit';

    public string $engagedOn = '';

    public string $engagementNote = '';

    // ── Contact form ────────────────────────────────────────────────────
    public bool $showContactForm = false;

    public string $contactOccupation = '';

    public string $contactOrganisation = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $contactNotes = '';

    public function mount(int $alumnus): void
    {
        Gate::authorize(Permission::AlumniView->value);

        /** @var AlumnusRecord $record */
        $record = AlumnusRecord::query()->findOrFail($alumnus);
        $this->alumnusId = (int) $record->getKey();
        $this->engagedOn = Carbon::now()->toDateString();
    }

    public function recordEngagement(RecordEngagement $recordEngagement): void
    {
        Gate::authorize(RecordEngagement::PERMISSION);

        $this->validate([
            'engagementType' => ['required', 'in:donation,visit,talk,mentorship,other'],
            'engagedOn' => ['required', 'date'],
            'engagementNote' => ['required', 'string', 'max:500'],
        ], [], [
            'engagementType' => __('alumni.form_type'),
            'engagedOn' => __('alumni.form_date'),
            'engagementNote' => __('alumni.form_note'),
        ]);

        try {
            $recordEngagement->handle($this->alumnusId, [
                'type' => $this->engagementType,
                'engaged_on' => $this->engagedOn,
                'note' => $this->engagementNote,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('engagementNote', $e->getMessage());

            return;
        }

        $this->reset(['engagementNote']);
        $this->engagedOn = Carbon::now()->toDateString();
        session()->flash('status', __('alumni.engagement_recorded'));
    }

    public function toggleContactForm(): void
    {
        Gate::authorize(UpdateAlumnusContact::PERMISSION);

        $this->showContactForm = ! $this->showContactForm;

        if ($this->showContactForm) {
            $record = $this->record();
            $this->contactOccupation = (string) $record->current_occupation;
            $this->contactOrganisation = (string) $record->current_organisation;
            $this->contactEmail = (string) $record->contact_email;
            $this->contactPhone = (string) $record->contact_phone;
            $this->contactNotes = (string) $record->notes;
        }
    }

    public function saveContact(UpdateAlumnusContact $updateContact): void
    {
        Gate::authorize(UpdateAlumnusContact::PERMISSION);

        $this->validate([
            'contactOccupation' => ['nullable', 'string', 'max:120'],
            'contactOrganisation' => ['nullable', 'string', 'max:160'],
            'contactEmail' => ['nullable', 'email', 'max:160'],
            'contactPhone' => ['nullable', 'string', 'max:24'],
            'contactNotes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'contactEmail' => __('alumni.form_email'),
            'contactPhone' => __('alumni.form_phone'),
        ]);

        $updateContact->handle($this->alumnusId, [
            'current_occupation' => $this->contactOccupation,
            'current_organisation' => $this->contactOrganisation,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'notes' => $this->contactNotes,
        ], $this->actor());

        $this->showContactForm = false;
        session()->flash('status', __('alumni.contact_updated'));
    }

    public function markDeceased(MarkDeceased $markDeceased): void
    {
        Gate::authorize(MarkDeceased::PERMISSION);

        try {
            $markDeceased->handle($this->alumnusId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('deceased', $e->getMessage());

            return;
        }

        session()->flash('status', __('alumni.marked_deceased'));
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    private function record(): AlumnusRecord
    {
        /** @var AlumnusRecord $record */
        $record = AlumnusRecord::query()->findOrFail($this->alumnusId);

        return $record;
    }

    /**
     * The identity half, read across the boundary via DB::table only.
     *
     * @return object{first_name: string, last_name: string, matricule: string, gender: string, date_of_birth: string}
     */
    private function studentRow(AlumnusRecord $record): object
    {
        /** @var object{first_name: string, last_name: string, matricule: string, gender: string, date_of_birth: string}|null $row */
        $row = DB::table('students')
            ->where('id', $record->student_id)
            ->first(['first_name', 'last_name', 'matricule', 'gender', 'date_of_birth']);

        if ($row === null) {
            abort(404);
        }

        return $row;
    }

    public function render(): mixed
    {
        $record = $this->record();

        $engagements = AlumniEngagement::query()
            ->where('alumnus_record_id', $this->alumnusId)
            ->orderByDesc('engaged_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('livewire.alumni.show', [
            'record' => $record,
            'student' => $this->studentRow($record),
            'engagements' => $engagements,
            'typeOptions' => EngagementType::cases(),
        ]);
    }
}
