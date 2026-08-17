<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Guardians;

use App\Modules\Guardians\Actions\IssuePortalInvitation;
use App\Modules\Guardians\Actions\RevokePortalInvitation;
use App\Modules\Guardians\Actions\SetGuardianAuthorization;
use App\Modules\Guardians\Actions\SetGuardianPhoto;
use App\Modules\Guardians\Actions\UnlinkGuardian;
use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\GuardianCommunication;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Guardians\Models\PortalInvitation;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Guardian Profile, docs/specs/07-students.md 11.3.
 *
 * ── What ships and what does not ──────────────────────────────────────────
 *
 * Live: the guardian record (7.1), the Linked Students table (7.2) WITH the
 * Permissions column 11.3 explicitly adds to the mockup, Meetings and
 * Communication History (7.8). The last two have real tables that nothing
 * writes to yet, so they render a real empty state - which is the honest
 * answer, and 7.8 even says `queued` with no connectivity is the normal steady
 * state, so an empty log is not a fault.
 *
 * Inert: Address & Contact (its content is already on this page, and a tab
 * that duplicates a card is not a feature), Documents (GuardianDocument has no
 * table in Phase 2) and Payments (04-fees).
 *
 * ── Student columns come from the query builder ───────────────────────────
 *
 * StudentGuardian deliberately has NO relation to Student -
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from using
 * App\Modules\Students\Models, and that model's header records the sanctioned
 * alternative: join `students` by `student_id` in a query. So the linked-
 * students table gets its name / admission number / class / status from one
 * query-builder statement, and its authorization column from the links
 * themselves - the flags never leave the module that owns the rule.
 *
 * ── Linked Students is no longer read-only ────────────────────────────────
 *
 * Two write actions now live on the Linked Students table, and both go
 * through their Action rather than an in-place edit, exactly as 7.6
 * requires: `unlinkGuardian()` calls UnlinkGuardian (`valid_to =
 * business_date()` + mandatory reason, no hard delete), and
 * `setAuthorization()` calls SetGuardianAuthorization (close-and-succeed:
 * the current row is closed today and a successor with the new flags starts
 * tomorrow, both written to the audit log). Neither method mutates a
 * StudentGuardian column directly - both re-gate on `guardians.manage`
 * before calling into the module's own Action, which is what keeps the
 * audit trail 7.6 exists to protect intact.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    use WithFileUploads;

    private const TAB_LIST_LIMIT = 50;

    public Guardian $guardian;

    /** @var list<string> */
    public const LIVE_TABS = ['linked_students', 'meetings', 'communications', 'portal'];

    /**
     * The plaintext invitation code, present ONLY between issuing and the
     * next navigation. It is never persisted anywhere - the database holds
     * its SHA-256 - so this transient property is the single place the
     * operator can copy it from (Phase 12, docs/plans/phase-12-13.md 12.2).
     */
    public ?string $issuedCode = null;

    /** @var list<string> */
    public const DISABLED_TABS = ['address', 'documents', 'payments'];

    #[Url]
    public string $tab = 'linked_students';

    // ── Unlink form (opened per row) ─────────────────────────────────────
    public bool $showUnlinkForm = false;

    public ?int $unlinkLinkId = null;

    public string $unlinkReason = '';

    // ── Set Authorization form (opened per row) ──────────────────────────
    public bool $showAuthorizationForm = false;

    public ?int $authorizationLinkId = null;

    public bool $authIsPrimary = false;

    public bool $authHasCustody = false;

    public bool $authReceivesReports = false;

    public bool $authReceivesInvoices = false;

    public bool $authIsEmergencyContact = false;

    public bool $authIsAuthorisedForPickup = false;

    public bool $authIsFeePayer = false;

    public string $authorizationReason = '';

    // ── Photograph ───────────────────────────────────────────────────────
    public ?TemporaryUploadedFile $photoUpload = null;

    public function mount(Guardian $guardian): void
    {
        // routes/web.php gates guardians.show on students.view: a guardian
        // record is read by whoever may read the student it belongs to. The
        // component repeats the check because a Livewire component is
        // independently addressable over the wire (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);

        $this->guardian = $guardian;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::LIVE_TABS, true) ? $tab : 'linked_students';

        // Leaving the portal tab discards the one-time code display.
        if ($this->tab !== 'portal') {
            $this->issuedCode = null;
        }
    }

    /**
     * Issue (or reissue - the Action revokes the predecessor) an activation
     * code for this guardian. The Action gates on portal.manage; the
     * component repeats nothing because a thrown AuthorizationException is
     * already the correct outcome for a forged wire call.
     */
    public function issuePortalInvitation(): void
    {
        $result = app(IssuePortalInvitation::class)->handle(
            PortalSubjectType::Guardian,
            (int) $this->guardian->getKey(),
        );

        $this->issuedCode = $result['code'];
        $this->tab = 'portal';
    }

    public function revokePortalInvitation(int $invitationId): void
    {
        $invitation = PortalInvitation::query()
            ->where('subject_type', PortalSubjectType::Guardian->value)
            ->where('subject_id', $this->guardian->getKey())
            ->findOrFail($invitationId);

        app(RevokePortalInvitation::class)->handle($invitation);

        $this->issuedCode = null;
    }

    /**
     * The open (still redeemable) invitation for this guardian, if any.
     */
    private function openInvitation(): ?PortalInvitation
    {
        return PortalInvitation::query()
            ->where('subject_type', PortalSubjectType::Guardian->value)
            ->where('subject_id', $this->guardian->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->orderByDesc('issued_at')
            ->first();
    }

    /**
     * The linked portal account's email, resolved through the query builder:
     * users belongs to Identity, whose Models this module must not import.
     */
    private function portalUserEmail(): ?string
    {
        if ($this->guardian->portal_user_id === null) {
            return null;
        }

        $email = DB::table('users')->where('id', $this->guardian->portal_user_id)->value('email');

        return is_string($email) ? $email : null;
    }

    private function activeTab(): string
    {
        return in_array($this->tab, self::LIVE_TABS, true) ? $this->tab : 'linked_students';
    }

    /**
     * Every link this guardian holds, current or ended.
     *
     * Ended links are shown, not hidden: 7.2 has no hard delete, and an
     * operator investigating "why can this person still see the fees" needs to
     * see the row that answers it. The validity pill tells them apart.
     *
     * @return Collection<int, StudentGuardian>
     */
    private function links(): Collection
    {
        return $this->guardian->links()
            ->orderByDesc('is_primary')
            ->orderByDesc('valid_from')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * Student columns for the links on screen, keyed by student id. One query,
     * query builder only - see the class header.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{name: string, admission_no: string, status: string, class_name: string|null}>
     */
    private function studentRows(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $rows = DB::table('students as s')
            ->whereIn('s.id', $studentIds)
            ->leftJoin('enrollments as enr', function ($join): void {
                $join->on('enr.student_id', '=', 's.id')
                    ->whereIn('enr.status', ['pending', 'active', 'suspended']);
            })
            ->leftJoin('enrollment_segments as seg', function ($join): void {
                $join->on('seg.enrollment_id', '=', 'enr.id')->whereNull('seg.ends_on');
            })
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->select([
                's.id as id',
                's.first_name as first_name',
                's.middle_name as middle_name',
                's.last_name as last_name',
                's.admission_no as admission_no',
                's.status as status',
                'cg.name as class_name',
            ])
            ->get();

        $byId = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, middle_name: string|null, last_name: string, admission_no: string, status: string, class_name: string|null} $row */
            $byId[(int) $row->id] = [
                'name' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))),
                'admission_no' => (string) $row->admission_no,
                'status' => (string) $row->status,
                'class_name' => is_string($row->class_name) ? $row->class_name : null,
            ];
        }

        return $byId;
    }

    /**
     * @return Collection<int, GuardianMeeting>
     */
    private function meetings(): Collection
    {
        return GuardianMeeting::query()
            ->where('guardian_id', '=', $this->guardian->id)
            ->orderByDesc('scheduled_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, GuardianCommunication>
     */
    private function communications(): Collection
    {
        return GuardianCommunication::query()
            ->where('guardian_id', '=', $this->guardian->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    // ── Unlink ────────────────────────────────────────────────────────────

    public function toggleUnlinkForm(?int $linkId = null): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->showUnlinkForm = ! $this->showUnlinkForm || $this->unlinkLinkId !== $linkId;
        $this->unlinkLinkId = $this->showUnlinkForm ? $linkId : null;
        $this->unlinkReason = '';

        if ($this->showUnlinkForm) {
            $this->showAuthorizationForm = false;
            $this->authorizationLinkId = null;
        }
    }

    public function unlinkGuardian(UnlinkGuardian $unlinkGuardian): void
    {
        Gate::authorize(Permission::GuardiansManage);

        if ($this->unlinkLinkId === null) {
            $this->addError('unlinkReason', 'No link was selected to unlink.');

            return;
        }

        $link = StudentGuardian::query()->find($this->unlinkLinkId);

        if ($link === null) {
            $this->addError('unlinkReason', 'This guardian link no longer exists.');

            return;
        }

        try {
            $unlinkGuardian->handle($link, $this->unlinkReason, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('unlinkReason', $e->getMessage());

            return;
        }

        $this->showUnlinkForm = false;
        $this->unlinkLinkId = null;
        $this->unlinkReason = '';
        session()->flash('status', 'Guardian link ended.');
    }

    // ── Set Authorization ────────────────────────────────────────────────

    public function toggleAuthorizationForm(?int $linkId = null): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->showAuthorizationForm = ! $this->showAuthorizationForm || $this->authorizationLinkId !== $linkId;
        $this->authorizationLinkId = $this->showAuthorizationForm ? $linkId : null;
        $this->authorizationReason = '';

        if ($this->showAuthorizationForm) {
            $this->showUnlinkForm = false;
            $this->unlinkLinkId = null;

            $link = $linkId === null ? null : StudentGuardian::query()->find($linkId);

            $this->authIsPrimary = (bool) ($link?->is_primary ?? false);
            $this->authHasCustody = (bool) ($link?->has_custody ?? false);
            $this->authReceivesReports = (bool) ($link?->receives_reports ?? false);
            $this->authReceivesInvoices = (bool) ($link?->receives_invoices ?? false);
            $this->authIsEmergencyContact = (bool) ($link?->is_emergency_contact ?? false);
            $this->authIsAuthorisedForPickup = (bool) ($link?->is_authorised_for_pickup ?? false);
            $this->authIsFeePayer = (bool) ($link?->is_fee_payer ?? false);
        }
    }

    public function setAuthorization(SetGuardianAuthorization $setGuardianAuthorization): void
    {
        Gate::authorize(Permission::GuardiansManage);

        if ($this->authorizationLinkId === null) {
            $this->addError('authorizationReason', 'No link was selected.');

            return;
        }

        $link = StudentGuardian::query()->find($this->authorizationLinkId);

        if ($link === null) {
            $this->addError('authorizationReason', 'This guardian link no longer exists.');

            return;
        }

        try {
            $setGuardianAuthorization->handle(
                $link,
                [
                    'is_primary' => $this->authIsPrimary,
                    'has_custody' => $this->authHasCustody,
                    'receives_reports' => $this->authReceivesReports,
                    'receives_invoices' => $this->authReceivesInvoices,
                    'is_emergency_contact' => $this->authIsEmergencyContact,
                    'is_authorised_for_pickup' => $this->authIsAuthorisedForPickup,
                    'is_fee_payer' => $this->authIsFeePayer,
                ],
                $this->authorizationReason,
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('authorizationReason', $e->getMessage());

            return;
        }

        $this->showAuthorizationForm = false;
        $this->authorizationLinkId = null;
        $this->authorizationReason = '';
        session()->flash('status', 'Authorization updated. The change takes effect tomorrow, unless the link had not started yet.');
    }

    // ── Photograph ───────────────────────────────────────────────────────

    /**
     * `image` plus an explicit mimes list plus a dimension cap, all three -
     * the same reasoning as DocumentProfile: `image` alone admits SVG (a
     * script-capable document served from this app's own origin), the mimes
     * list alone admits a 12 000 px scan, and the dimension cap alone admits
     * a renamed executable.
     *
     * @return array<string, mixed>
     */
    private function photoRules(): array
    {
        return [
            'photoUpload' => [
                'required',
                'image',
                'mimes:'.implode(',', StoredImage::ALLOWED_EXTENSIONS),
                'max:'.StoredImage::MAX_KILOBYTES,
                'dimensions:max_width='.StoredImage::MAX_DIMENSION.',max_height='.StoredImage::MAX_DIMENSION,
            ],
        ];
    }

    public function savePhoto(SetGuardianPhoto $setGuardianPhoto): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->validate($this->photoRules(), [
            'photoUpload.required' => (string) __('opes.guardians_screen.photo_required'),
            'photoUpload.image' => (string) __('opes.school_identity.upload_not_an_image'),
            'photoUpload.mimes' => (string) __('opes.school_identity.upload_wrong_type', [
                'types' => strtoupper(implode(', ', StoredImage::ALLOWED_EXTENSIONS)),
            ]),
            'photoUpload.max' => (string) __('opes.school_identity.upload_too_large', [
                'kb' => StoredImage::MAX_KILOBYTES,
            ]),
            'photoUpload.dimensions' => (string) __('opes.school_identity.upload_too_big', [
                'px' => StoredImage::MAX_DIMENSION,
            ]),
        ]);

        /** @var TemporaryUploadedFile $upload */
        $upload = $this->photoUpload;

        $setGuardianPhoto->handle($this->guardian, $upload, $this->actor());

        // Released immediately: a TemporaryUploadedFile left on a public
        // property is re-serialised into every subsequent request's payload.
        $upload->delete();
        $this->photoUpload = null;

        session()->flash('status', (string) __('opes.guardians_screen.photo_saved'));
    }

    public function removePhoto(SetGuardianPhoto $setGuardianPhoto): void
    {
        Gate::authorize(Permission::GuardiansManage);

        $this->photoUpload?->delete();
        $this->photoUpload = null;

        $setGuardianPhoto->handle($this->guardian, null, $this->actor());

        session()->flash('status', (string) __('opes.guardians_screen.photo_removed'));
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    public function render(): mixed
    {
        $tab = $this->activeTab();
        $links = $this->links();

        /** @var list<int> $studentIds */
        $studentIds = $links->pluck('student_id')->unique()->values()->all();

        return view('livewire.guardians.show', [
            'tab' => $tab,
            // The left rail shows a Linked Students preview on every tab, so
            // the links are always resolved - they are also the cheapest query
            // on the page and are capped.
            'links' => $links,
            'studentRows' => $this->studentRows($studentIds),
            'meetings' => $tab === 'meetings' ? $this->meetings() : new Collection(),
            'communications' => $tab === 'communications' ? $this->communications() : new Collection(),
            'openInvitation' => $tab === 'portal' ? $this->openInvitation() : null,
            'portalUserEmail' => $tab === 'portal' ? $this->portalUserEmail() : null,
            'canManagePortal' => Gate::allows(Permission::PortalManage->value),
            'canManageGuardians' => Gate::allows(Permission::GuardiansManage->value),
        ]);
    }
}
