<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Guardians;

use App\Modules\Guardians\Actions\CreateGuardian;
use App\Modules\Guardians\Actions\LinkGuardian;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Guardians directory at /guardians, docs/specs/07-students.md 7.1: the
 * list screen the placeholder nav entry (Navigation.php `built => false`)
 * has been pointing at nothing. Gated `guardians.manage`, the same
 * permission that governs writes to the records this screen only reads.
 *
 * Cross-module reads (linked-student counts) go through DB::table joins
 * only - never another module's Models (ModuleBoundaryTest); the pivot is
 * `student_guardians`, joined by `guardian_id`, filtered to open links
 * (`valid_to IS NULL`) for the "linked students" count so a revoked link
 * does not keep inflating a guardian's count forever.
 *
 * Two toggle-forms live here now: "Add Guardian" (CreateGuardian, 7.1/7.7 -
 * surfaces the duplicate tier CreateGuardian detects rather than blocking on
 * anything but a tier-1 ID-number collision) and "Link to Student"
 * (LinkGuardian, 7.2 - opened per-row from the directory so the guardian is
 * already known, the operator only supplies the student and the link
 * flags). Both gate on `guardians.manage`, the same permission the Actions
 * themselves enforce. Everything else on this screen stays read-only; see
 * Show.php for revoking/amending an existing link.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $relationship = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Add Guardian form ───────────────────────────────────────────────
    public bool $showCreateForm = false;

    public string $createFirstName = '';

    public string $createLastName = '';

    public string $createGender = 'male';

    public string $createPhone = '';

    public string $createAlternativePhone = '';

    public string $createEmail = '';

    public string $createDateOfBirth = '';

    public string $createIdNumber = '';

    // ── Link Guardian to Student form (opened per row) ──────────────────
    public bool $showLinkForm = false;

    public ?int $linkGuardianId = null;

    public string $linkGuardianLabel = '';

    public string $linkStudentAdmissionNo = '';

    public string $linkRelationship = 'father';

    public string $linkRelationshipOther = '';

    public bool $linkIsPrimary = false;

    public bool $linkHasCustody = false;

    public bool $linkReceivesReports = false;

    public bool $linkReceivesInvoices = false;

    public bool $linkIsEmergencyContact = false;

    public bool $linkIsAuthorisedForPickup = false;

    public bool $linkIsFeePayer = false;

    public function mount(): void
    {
        Gate::authorize(Permission::GuardiansManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRelationship(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'relationship']);
        $this->resetPage();
    }

    /**
     * Guardians with their open-link student count, the "linked students"
     * column of 7.1's directory. `relationship` filters to guardians who
     * hold at least one open link of that kind - a per-pivot-row attribute,
     * not a column on `guardians` itself, hence the EXISTS join rather than
     * a simple WHERE.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return DB::table('guardians as g')
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('g.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('g.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('g.phone', 'like', '%'.$this->search.'%')
                        ->orWhere('g.email', 'like', '%'.$this->search.'%')
                        ->orWhere('g.guardian_no', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->relationship !== '', function ($q): void {
                $q->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('student_guardians as sg')
                        ->whereColumn('sg.guardian_id', 'g.id')
                        ->whereNull('sg.valid_to')
                        ->where('sg.relationship', $this->relationship);
                });
            })
            ->orderBy('g.last_name')
            ->orderBy('g.first_name')
            ->select([
                'g.id', 'g.guardian_no', 'g.first_name', 'g.last_name',
                'g.phone', 'g.email', 'g.status', 'g.portal_user_id',
            ])
            ->selectSub(
                DB::table('student_guardians')
                    ->whereColumn('guardian_id', 'g.id')
                    ->whereNull('valid_to')
                    ->selectRaw('COUNT(*)'),
                'students_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Dataset-wide KPI strip: total guardians, guardians with zero
     * currently-open student links (orphaned records worth a look), and
     * guardians with an activated portal account - safely derivable
     * because `guardians.portal_user_id` is set only once ActivatePortalAccount
     * has run (Phase 12), so it needs no extra join.
     *
     * @return array{total: int, orphaned: int, portal_active: int}
     */
    private function kpis(): array
    {
        $total = (int) DB::table('guardians')->count();

        $orphaned = (int) DB::table('guardians as g')
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('student_guardians as sg')
                    ->whereColumn('sg.guardian_id', 'g.id')
                    ->whereNull('sg.valid_to');
            })
            ->count();

        $portalActive = (int) DB::table('guardians')->whereNotNull('portal_user_id')->count();

        return [
            'total' => $total,
            'orphaned' => $orphaned,
            'portal_active' => $portalActive,

            // Linked children, not linked guardians: the question a registrar
            // asks of this screen is "how many pupils have somebody
            // answerable for them", and one guardian standing for four
            // children is four covered pupils, not one.
            'linked_students' => (int) DB::table('student_guardians')
                ->whereNull('valid_to')
                ->distinct()
                ->count('student_id'),

            // Live links with no portal account behind them. This is the
            // actionable number on the screen: a guardian the school can
            // reach on paper but not in the app.
            'without_portal' => max(0, $total - $portalActive),
        ];
    }

    /**
     * Guardians per relationship, for the rail donut.
     *
     * Counts the LINK, not the guardian: the same person can be a mother to
     * one pupil and a legal guardian to another, and collapsing that to one
     * row would hide the second relationship entirely.
     *
     * @return list<array{label: string, value: int}>
     */
    private function relationshipDistribution(): array
    {
        return DB::table('student_guardians')
            ->whereNull('valid_to')
            ->whereNotNull('relationship')
            ->groupBy('relationship')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->selectRaw('relationship as label, COUNT(*) as value')
            ->get()
            ->map(static fn (object $r): array => [
                'label' => ucfirst(str_replace('_', ' ', (string) $r->label)),
                'value' => (int) $r->value,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function relationshipOptions(): array
    {
        $options = [];

        foreach (DB::table('student_guardians')
            ->whereNull('valid_to')
            ->distinct()
            ->orderBy('relationship')
            ->pluck('relationship') as $value) {
            /** @var string $value */
            $options[] = ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))];
        }

        return $options;
    }

    // ── Add Guardian ─────────────────────────────────────────────────────

    public function toggleCreateForm(): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->showCreateForm = ! $this->showCreateForm;

        if (! $this->showCreateForm) {
            $this->resetCreateForm();
        }
    }

    public function saveGuardian(CreateGuardian $createGuardian): void
    {
        Gate::authorize(Permission::GuardiansManage);

        try {
            $result = $createGuardian->handle([
                'first_name' => $this->createFirstName,
                'last_name' => $this->createLastName,
                'gender' => $this->createGender,
                'phone' => $this->createPhone,
                'alternative_phone' => $this->createAlternativePhone === '' ? null : $this->createAlternativePhone,
                'email' => $this->createEmail === '' ? null : $this->createEmail,
                'date_of_birth' => $this->createDateOfBirth === '' ? null : $this->createDateOfBirth,
                'id_number' => trim($this->createIdNumber) === '' ? null : trim($this->createIdNumber),
            ], $this->actor());
        } catch (ValidationException $e) {
            // The tier-1 refusal (7.7) names the ID number, and it must land
            // on the ID field the operator just filled - not on the phone box,
            // which is where every other CreateGuardian failure surfaces.
            $field = array_key_exists('id_number', $e->errors()) ? 'createIdNumber' : 'createPhone';

            $this->addError($field, $e->getMessage());

            return;
        }

        $guardian = $result['guardian'];

        $message = sprintf('Guardian %s (%s) created.', $guardian->fullName(), $guardian->guardian_no);

        // FindDuplicateGuardians ran inside CreateGuardian; a non-null tier
        // here means tier 2 (phone) or 3 (name + DOB) matched - genuinely
        // ambiguous cases 7.7 says must not block creation, only be
        // surfaced so the operator can link instead if it turns out to be
        // the same person.
        if ($result['duplicate_tier'] !== null) {
            $message .= ' A possible duplicate was found - review the directory before assuming this is a new person.';
        }

        $this->resetCreateForm();
        $this->resetPage();
        session()->flash('status', $message);
    }

    private function resetCreateForm(): void
    {
        $this->reset([
            'showCreateForm', 'createFirstName', 'createLastName', 'createGender',
            'createPhone', 'createAlternativePhone', 'createEmail', 'createDateOfBirth',
            'createIdNumber',
        ]);
        $this->createGender = 'male';
    }

    // ── Link Guardian to Student ─────────────────────────────────────────

    public function toggleLinkForm(?int $guardianId = null, string $guardianLabel = ''): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->showLinkForm = ! $this->showLinkForm || $this->linkGuardianId !== $guardianId;
        $this->linkGuardianId = $this->showLinkForm ? $guardianId : null;
        $this->linkGuardianLabel = $this->showLinkForm ? $guardianLabel : '';

        if (! $this->showLinkForm) {
            $this->resetLinkForm();
        }
    }

    public function saveLink(LinkGuardian $linkGuardian): void
    {
        Gate::authorize(Permission::GuardiansManage);

        if ($this->linkGuardianId === null) {
            $this->addError('linkStudentAdmissionNo', 'No guardian was selected to link.');

            return;
        }

        $studentId = DB::table('students')
            ->where('admission_no', '=', $this->linkStudentAdmissionNo)
            ->value('id');

        if ($studentId === null) {
            $this->addError('linkStudentAdmissionNo', 'No student matches that admission number.');

            return;
        }

        $relationship = GuardianRelationship::tryFrom($this->linkRelationship);

        if ($relationship === null) {
            $this->addError('linkRelationship', 'Unknown relationship.');

            return;
        }

        try {
            $linkGuardian->handle(
                studentId: (int) $studentId,
                guardianId: $this->linkGuardianId,
                relationship: $relationship,
                relationshipOther: $this->linkRelationshipOther === '' ? null : $this->linkRelationshipOther,
                isPrimary: $this->linkIsPrimary,
                hasCustody: $this->linkHasCustody,
                receivesReports: $this->linkReceivesReports,
                receivesInvoices: $this->linkReceivesInvoices,
                isEmergencyContact: $this->linkIsEmergencyContact,
                isAuthorisedForPickup: $this->linkIsAuthorisedForPickup,
                isFeePayer: $this->linkIsFeePayer,
                actor: $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('linkStudentAdmissionNo', $e->getMessage());

            return;
        }

        $this->resetLinkForm();
        $this->resetPage();
        session()->flash('status', 'Guardian linked to student.');
    }

    private function resetLinkForm(): void
    {
        $this->reset([
            'showLinkForm', 'linkGuardianId', 'linkGuardianLabel', 'linkStudentAdmissionNo',
            'linkRelationship', 'linkRelationshipOther', 'linkIsPrimary', 'linkHasCustody',
            'linkReceivesReports', 'linkReceivesInvoices', 'linkIsEmergencyContact',
            'linkIsAuthorisedForPickup', 'linkIsFeePayer',
        ]);
        $this->linkRelationship = 'father';
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    public function render(): mixed
    {
        return view('livewire.guardians.guardians.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'relationshipDistribution' => $this->relationshipDistribution(),
            'relationshipOptions' => $this->relationshipOptions(),
            'relationshipCases' => GuardianRelationship::cases(),
        ]);
    }
}
