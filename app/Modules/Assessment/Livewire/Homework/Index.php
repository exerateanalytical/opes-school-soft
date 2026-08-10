<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Homework;

use App\Modules\Assessment\Actions\Homework\GradeAssignmentSubmission;
use App\Modules\Assessment\Actions\Homework\SetAssignment;
use App\Modules\Assessment\Models\Assignment;
use App\Modules\Forms\Concerns\AutosavesDraft;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The teacher's homework screen: set an assignment for a class/subject,
 * then grade submissions as students turn work in.
 *
 * The "set an assignment" form is this session's flagship for the
 * universal popup-form pattern: a real modal (x-opes-modal-form), autosaved
 * on every field change, and hold-able mid-fill via AutosavesDraft - set up
 * a homework assignment, get pulled away, hold it, and it surfaces on
 * /unfinished-work until resumed or discarded.
 */
final class Index extends Component
{
    use AutosavesDraft;

    public ?int $selectedAssignmentId = null;

    public bool $showForm = false;

    public string $classGroupId = '';

    public string $subjectId = '';

    public string $title = '';

    public string $instructions = '';

    public string $assignedOn = '';

    public string $dueOn = '';

    public string $maxScore = '';

    public array $scoreDrafts = [];

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $this->assignedOn = now()->toDateString();
        $this->dueOn = now()->addWeek()->toDateString();
        $this->initializeAutosave();

        if ($this->resumedFromDraft) {
            $this->showForm = true;
        }
    }

    public function updated($property): void
    {
        if ($this->showForm) {
            $this->autosave();
        }
    }

    public function formKey(): string
    {
        return 'assessment.homework.create';
    }

    /**
     * @return array<string, mixed>
     */
    public function draftableState(): array
    {
        return [
            'classGroupId' => $this->classGroupId,
            'subjectId' => $this->subjectId,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'assignedOn' => $this->assignedOn,
            'dueOn' => $this->dueOn,
            'maxScore' => $this->maxScore,
        ];
    }

    public function hold(): void
    {
        $label = $this->title !== ''
            ? __('opes.homework_screen.title_label').': '.$this->title
            : null;

        $this->holdCurrentDraft($label);
        $this->showForm = false;
        $this->message = __('opes.unfinished_work.title').' ✓';
    }

    public function create(): void
    {
        $this->message = '';
        $this->error = '';

        $userId = (int) Auth::id();

        try {
            $assignment = app(SetAssignment::class)->handle(
                (int) $this->classGroupId,
                (int) $this->subjectId,
                $userId,
                $this->title,
                $this->assignedOn,
                $this->dueOn,
                $this->instructions !== '' ? $this->instructions : null,
                $this->maxScore !== '' ? (float) $this->maxScore : null,
            );

            $this->clearDraft();

            $this->selectedAssignmentId = (int) $assignment->getKey();
            $this->showForm = false;
            $this->title = '';
            $this->instructions = '';
            $this->maxScore = '';
            $this->message = __('opes.homework_screen.created');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function grade(int $submissionId): void
    {
        $this->message = '';
        $this->error = '';

        $score = $this->scoreDrafts[$submissionId] ?? null;

        if ($score === null || $score === '') {
            return;
        }

        try {
            app(GradeAssignmentSubmission::class)->handle($submissionId, (float) $score, (int) Auth::id());
            $this->message = __('opes.homework_screen.graded');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $assignments = Assignment::query()
            ->where('set_by_user_id', (int) Auth::id())
            ->orderByDesc('due_on')
            ->limit(30)
            ->get();

        $selected = $this->selectedAssignmentId === null
            ? null
            : Assignment::query()->find($this->selectedAssignmentId);

        $submissions = $selected === null
            ? collect()
            : DB::table('assignment_submissions as s')
                ->join('enrollments as e', 'e.id', '=', 's.enrollment_id')
                ->join('students as st', 'st.id', '=', 'e.student_id')
                ->where('s.assignment_id', $selected->getKey())
                ->orderBy('st.last_name')
                ->get(['s.id', 'st.first_name', 'st.last_name', 's.submitted_at', 's.is_late', 's.score', 's.graded_at']);

        return view('livewire.assessment.homework.index', [
            'classGroups' => DB::table('class_groups')->orderBy('name')->limit(100)->get(),
            'subjects' => DB::table('subjects')->orderBy('name')->limit(100)->get(),
            'assignments' => $assignments,
            'selected' => $selected,
            'submissions' => $submissions,
        ]);
    }
}
