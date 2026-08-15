<?php

declare(strict_types=1);

namespace App\Modules\Activities\Livewire;

use App\Modules\Activities\Actions\CloseActivity;
use App\Modules\Activities\Actions\EnrolStudent;
use App\Modules\Activities\Actions\RecordConsent;
use App\Modules\Activities\Actions\RecordSessionAttendance;
use App\Modules\Activities\Actions\ScheduleSession;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Domain\MembershipRole;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;

/**
 * One activity's detail page at /activities/{activity}, gated
 * `activity.view` (the Index's own gate) - header card with the trip
 * facts for an excursion, then Members / Sessions / Attendance tabs, plus
 * a Consent tab that exists only for excursions (gap-analysis row 15).
 * This is the page the Index's row click actually reaches - detail pages
 * being the platform's known weakness, the row link is the point.
 *
 * All writes go through the module's Actions and are re-gated
 * `activity.manage` here (screen-vs-write split every module uses).
 * Cross-module reads (student names, guardians, staff) go through
 * DB::table joins only - never another module's Models
 * (ModuleBoundaryTest). Every list is capped (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    /** Cap per list on this page. */
    private const int LIST_LIMIT = 200;

    public int $activityId;

    /** Which tab is showing: members | sessions | attendance | consent. */
    #[Url]
    public string $tab = 'members';

    // ── Enrol Student form ──────────────────────────────────────────────
    public bool $showEnrolForm = false;

    public string $enrolFormStudentId = '';

    public string $enrolFormRole = 'member';

    public string $enrolFormStartsOn = '';

    // ── Schedule Session form ───────────────────────────────────────────
    public bool $showSessionForm = false;

    public string $sessionFormDate = '';

    public string $sessionFormStartsAt = '';

    public string $sessionFormEndsAt = '';

    public string $sessionFormVenue = '';

    public string $sessionFormSupervisorId = '';

    public string $sessionFormNotes = '';

    // ── Attendance register ─────────────────────────────────────────────
    #[Url]
    public string $sessionId = '';

    /** @var array<int, string> membership id => status value */
    public array $attendanceMarks = [];

    // ── Consent form ────────────────────────────────────────────────────
    public string $consentFormMembershipId = '';

    public string $consentFormGuardianId = '';

    public string $consentFormDecision = 'granted';

    public string $consentFormNote = '';

    public function mount(int $activity): void
    {
        Gate::authorize(ActivityPermission::VIEW);

        $this->activityId = $activity;

        // 404 early rather than rendering an empty card.
        DB::table('activities')->where('id', $activity)->firstOrFail();
    }

    private function activityRow(): stdClass
    {
        /** @var stdClass $row */
        $row = DB::table('activities')->where('id', $this->activityId)->first();

        return $row;
    }

    private function isExcursion(): bool
    {
        return $this->activityRow()->type === 'excursion';
    }

    public function selectTab(string $tab): void
    {
        $allowed = ['members', 'sessions', 'attendance'];

        if ($this->isExcursion()) {
            $allowed[] = 'consent';
        }

        $this->tab = in_array($tab, $allowed, true) ? $tab : 'members';
    }

    public function updatedSessionId(): void
    {
        $this->attendanceMarks = [];
    }

    // ── Enrol ───────────────────────────────────────────────────────────

    public function toggleEnrolForm(): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->showEnrolForm = ! $this->showEnrolForm;

        if ($this->showEnrolForm && $this->enrolFormStartsOn === '') {
            $this->enrolFormStartsOn = Carbon::now()->format('Y-m-d');
        }
    }

    public function enrolStudent(EnrolStudent $enrolStudent): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->validate([
            'enrolFormStudentId' => ['required', 'integer', 'min:1'],
            'enrolFormRole' => ['required', 'string', 'in:member,captain,leader'],
            'enrolFormStartsOn' => ['required', 'date'],
        ], [], [
            'enrolFormStudentId' => 'student',
            'enrolFormRole' => 'role',
            'enrolFormStartsOn' => 'start date',
        ]);

        try {
            $enrolStudent->handle(
                $this->activityId,
                (int) $this->enrolFormStudentId,
                MembershipRole::from($this->enrolFormRole),
                Carbon::parse($this->enrolFormStartsOn),
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('enrolFormStudentId', $e->getMessage());

            return;
        }

        $this->reset(['showEnrolForm', 'enrolFormStudentId', 'enrolFormRole', 'enrolFormStartsOn']);
        $this->enrolFormRole = 'member';
        $this->tab = 'members';
        session()->flash('status', 'Student enrolled.');
    }

    // ── Sessions ────────────────────────────────────────────────────────

    public function toggleSessionForm(): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->showSessionForm = ! $this->showSessionForm;

        if ($this->showSessionForm && $this->sessionFormDate === '') {
            $this->sessionFormDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function scheduleSession(ScheduleSession $scheduleSession): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->validate([
            'sessionFormDate' => ['required', 'date'],
            'sessionFormStartsAt' => ['nullable', 'date_format:H:i'],
            'sessionFormEndsAt' => ['nullable', 'date_format:H:i'],
            'sessionFormVenue' => ['nullable', 'string', 'max:150'],
            'sessionFormSupervisorId' => ['nullable', 'integer', 'min:1'],
            'sessionFormNotes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'sessionFormDate' => 'date',
            'sessionFormStartsAt' => 'start time',
            'sessionFormEndsAt' => 'end time',
            'sessionFormVenue' => 'venue',
            'sessionFormSupervisorId' => 'supervisor',
            'sessionFormNotes' => 'notes',
        ]);

        try {
            $scheduleSession->handle($this->activityId, [
                'scheduled_on' => $this->sessionFormDate,
                'starts_at' => $this->sessionFormStartsAt,
                'ends_at' => $this->sessionFormEndsAt,
                'venue' => $this->sessionFormVenue,
                'supervisor_id' => $this->sessionFormSupervisorId !== '' ? (int) $this->sessionFormSupervisorId : null,
                'notes' => $this->sessionFormNotes,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('sessionForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('sessionFormDate', $e->getMessage());

            return;
        }

        $this->reset([
            'showSessionForm', 'sessionFormDate', 'sessionFormStartsAt', 'sessionFormEndsAt',
            'sessionFormVenue', 'sessionFormSupervisorId', 'sessionFormNotes',
        ]);
        $this->tab = 'sessions';
        session()->flash('status', 'Session scheduled.');
    }

    // ── Attendance ──────────────────────────────────────────────────────

    public function saveAttendance(RecordSessionAttendance $recordSessionAttendance): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        if ($this->sessionId === '') {
            $this->addError('sessionId', 'Choose a session first.');

            return;
        }

        $marks = [];

        foreach ($this->attendanceMarks as $membershipId => $status) {
            if ($status !== '') {
                $marks[(int) $membershipId] = $status;
            }
        }

        try {
            $written = $recordSessionAttendance->handle((int) $this->sessionId, $marks, $this->actor());
        } catch (ValidationException $e) {
            $first = array_values($e->errors())[0] ?? ['Invalid register.'];
            $this->addError('attendanceMarks', (string) ($first[0] ?? 'Invalid register.'));

            return;
        } catch (DomainException $e) {
            $this->addError('attendanceMarks', $e->getMessage());

            return;
        }

        session()->flash('status', $written.' attendance mark(s) recorded.');
    }

    // ── Consent ─────────────────────────────────────────────────────────

    public function recordConsent(RecordConsent $recordConsent): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->validate([
            'consentFormMembershipId' => ['required', 'integer', 'min:1'],
            'consentFormGuardianId' => ['required', 'integer', 'min:1'],
            'consentFormDecision' => ['required', 'string', 'in:granted,declined'],
            'consentFormNote' => ['nullable', 'string', 'max:500'],
        ], [], [
            'consentFormMembershipId' => 'member',
            'consentFormGuardianId' => 'guardian',
            'consentFormDecision' => 'decision',
            'consentFormNote' => 'note',
        ]);

        try {
            $recordConsent->handle(
                (int) $this->consentFormMembershipId,
                (int) $this->consentFormGuardianId,
                ConsentStatus::from($this->consentFormDecision),
                $this->consentFormNote,
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('consentFormGuardianId', $e->getMessage());

            return;
        }

        $this->reset(['consentFormMembershipId', 'consentFormGuardianId', 'consentFormNote']);
        $this->consentFormDecision = 'granted';
        session()->flash('status', 'Consent recorded.');
    }

    // ── Close ───────────────────────────────────────────────────────────

    public function closeActivity(CloseActivity $closeActivity): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        try {
            $closeActivity->handle($this->activityId, $this->actor());
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', 'Activity closed; all memberships ended.');
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    // ── Reads ───────────────────────────────────────────────────────────

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function memberRows(): \Illuminate\Support\Collection
    {
        return DB::table('activity_memberships as m')
            ->join('students as s', 's.id', '=', 'm.student_id')
            ->where('m.activity_id', $this->activityId)
            ->orderByRaw("FIELD(m.status, 'active', 'ended')")
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->limit(self::LIST_LIMIT)
            ->select([
                'm.id', 'm.role', 'm.starts_on', 'm.ends_on', 'm.status',
                'm.consent_status',
                's.id as student_id', 's.first_name', 's.last_name', 's.matricule',
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function sessionRows(): \Illuminate\Support\Collection
    {
        return DB::table('activity_sessions as ses')
            ->leftJoin('staff_members as st', 'st.id', '=', 'ses.supervisor_id')
            ->where('ses.activity_id', $this->activityId)
            ->orderByDesc('ses.scheduled_on')->orderByDesc('ses.id')
            ->limit(self::LIST_LIMIT)
            ->select([
                'ses.id', 'ses.scheduled_on', 'ses.starts_at', 'ses.ends_at',
                'ses.venue', 'ses.notes',
                'st.first_name as supervisor_first_name', 'st.last_name as supervisor_last_name',
            ])
            ->selectSub(
                DB::table('activity_attendance')
                    ->whereColumn('session_id', 'ses.id')
                    ->where('status', 'present')
                    ->selectRaw('COUNT(*)'),
                'present_count'
            )
            ->selectSub(
                DB::table('activity_attendance')->whereColumn('session_id', 'ses.id')->selectRaw('COUNT(*)'),
                'marked_count'
            )
            ->get();
    }

    /**
     * The register grid for the selected session: every ACTIVE member,
     * with any mark already recorded.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function registerRows(): \Illuminate\Support\Collection
    {
        if ($this->sessionId === '') {
            return collect();
        }

        return DB::table('activity_memberships as m')
            ->join('students as s', 's.id', '=', 'm.student_id')
            ->leftJoin('activity_attendance as att', function ($join): void {
                $join->on('att.membership_id', '=', 'm.id')
                    ->where('att.session_id', '=', (int) $this->sessionId);
            })
            ->where('m.activity_id', $this->activityId)
            ->where('m.status', 'active')
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->limit(self::LIST_LIMIT)
            ->select([
                'm.id', 's.first_name', 's.last_name', 's.matricule',
                'att.status as recorded_status',
            ])
            ->get();
    }

    /**
     * Consent tab rows (excursions only): each ACTIVE member with the
     * decision, the deciding guardian and when it was recorded.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function consentRows(): \Illuminate\Support\Collection
    {
        return DB::table('activity_memberships as m')
            ->join('students as s', 's.id', '=', 'm.student_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'm.consent_guardian_id')
            ->where('m.activity_id', $this->activityId)
            ->where('m.status', 'active')
            ->orderByRaw("FIELD(COALESCE(m.consent_status, 'pending'), 'pending', 'declined', 'granted')")
            ->orderBy('s.last_name')
            ->limit(self::LIST_LIMIT)
            ->select([
                'm.id', 'm.consent_status', 'm.consent_recorded_at', 'm.consent_note',
                's.first_name', 's.last_name', 's.matricule',
                'g.first_name as guardian_first_name', 'g.last_name as guardian_last_name',
            ])
            ->get();
    }

    /**
     * Guardian options for the consent form - the guardians currently
     * linked to the selected membership's student.
     *
     * @return list<array{id: int, name: string}>
     */
    private function guardianOptions(): array
    {
        if ($this->consentFormMembershipId === '') {
            return [];
        }

        /** @var object{student_id: int|string}|null $membership */
        $membership = DB::table('activity_memberships')
            ->where('id', (int) $this->consentFormMembershipId)
            ->where('activity_id', $this->activityId)
            ->first(['student_id']);

        if ($membership === null) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $options = [];

        $rows = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', (int) $membership->student_id)
            ->where('sg.valid_from', '<=', $today)
            ->where(function ($q) use ($today): void {
                $q->whereNull('sg.valid_to')->orWhere('sg.valid_to', '>=', $today);
            })
            ->orderBy('g.last_name')
            ->limit(20)
            ->get(['g.id', 'g.first_name', 'g.last_name']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name)];
        }

        return $options;
    }

    /**
     * @return array{members: int, sessions: int, attendance_rate: int, pending_consents: int}
     */
    private function stats(): array
    {
        $marked = (int) DB::table('activity_attendance as att')
            ->join('activity_sessions as ses', 'ses.id', '=', 'att.session_id')
            ->where('ses.activity_id', $this->activityId)
            ->count();

        $present = (int) DB::table('activity_attendance as att')
            ->join('activity_sessions as ses', 'ses.id', '=', 'att.session_id')
            ->where('ses.activity_id', $this->activityId)
            ->where('att.status', 'present')
            ->count();

        return [
            'members' => (int) DB::table('activity_memberships')
                ->where('activity_id', $this->activityId)
                ->where('status', 'active')
                ->count(),
            'sessions' => (int) DB::table('activity_sessions')
                ->where('activity_id', $this->activityId)
                ->count(),
            'attendance_rate' => $marked > 0 ? (int) round($present * 100 / $marked) : 0,
            'pending_consents' => (int) DB::table('activity_memberships')
                ->where('activity_id', $this->activityId)
                ->where('status', 'active')
                ->where('consent_status', 'pending')
                ->count(),
        ];
    }

    /**
     * Session picker options for the attendance tab.
     *
     * @return list<array{id: int, label: string}>
     */
    private function sessionOptions(): array
    {
        $options = [];

        $rows = DB::table('activity_sessions')
            ->where('activity_id', $this->activityId)
            ->orderByDesc('scheduled_on')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'scheduled_on', 'venue']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, scheduled_on: string, venue: string|null} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => $row->scheduled_on.($row->venue !== null ? ' — '.$row->venue : ''),
            ];
        }

        return $options;
    }

    public function render(): mixed
    {
        $activity = $this->activityRow();
        $isExcursion = $activity->type === 'excursion';

        if ($this->tab === 'consent' && ! $isExcursion) {
            $this->tab = 'members';
        }

        return view('livewire.activities.show', [
            'activity' => $activity,
            'isExcursion' => $isExcursion,
            'stats' => $this->stats(),
            'members' => $this->memberRows(),
            'sessions' => $this->sessionRows(),
            'registerRows' => $this->registerRows(),
            'sessionOptions' => $this->sessionOptions(),
            'consentRows' => $isExcursion ? $this->consentRows() : collect(),
            'guardianOptions' => $isExcursion ? $this->guardianOptions() : [],
            'canManage' => Gate::allows(ActivityPermission::MANAGE),
        ]);
    }
}
