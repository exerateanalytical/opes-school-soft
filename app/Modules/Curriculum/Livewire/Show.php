<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Livewire;

use App\Modules\Curriculum\Actions\AddCompetency;
use App\Modules\Curriculum\Actions\AddTopic;
use App\Modules\Curriculum\Actions\AddUnit;
use App\Modules\Curriculum\Actions\LinkTopicCompetency;
use App\Modules\Curriculum\Actions\PublishCurriculum;
use App\Modules\Curriculum\Actions\ReviseCurriculum;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Models\Curriculum;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;

/**
 * One curriculum version's file at /curriculum/{curriculum}: the version
 * banner (draft vs published, Publish / Revise controls), the ordered
 * units/topics tree, and the competencies tab with topic links.
 *
 * Same detail-page idiom as Welfare's discipline CaseShow: mount gates on
 * VIEW and 404s early; every write re-gates on MANAGE and delegates to an
 * Action.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $curriculumId;

    /** structure | competencies */
    public string $tab = 'structure';

    // ── Add Unit form ───────────────────────────────────────────────────
    public bool $showUnitForm = false;

    public string $unitFormTitle = '';

    public string $unitFormDescription = '';

    // ── Add Topic form (one open unit at a time) ────────────────────────
    public ?int $topicFormUnitId = null;

    public string $topicFormTitle = '';

    public string $topicFormOutcome = '';

    // ── Add Competency form ─────────────────────────────────────────────
    public bool $showCompetencyForm = false;

    public string $competencyFormCode = '';

    public string $competencyFormDescriptor = '';

    // ── Link Topic <-> Competency form ──────────────────────────────────
    public ?int $linkFormTopicId = null;

    public string $linkFormCompetencyId = '';

    public function mount(int $curriculum): void
    {
        Gate::authorize(CurriculumPermission::VIEW);

        $this->curriculumId = $curriculum;

        // 404 early rather than rendering an empty file.
        Curriculum::query()->findOrFail($curriculum);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['structure', 'competencies'], true) ? $tab : 'structure';
    }

    public function toggleUnitForm(): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->showUnitForm = ! $this->showUnitForm;
    }

    public function saveUnit(AddUnit $addUnit): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->validate([
            'unitFormTitle' => ['required', 'string', 'max:160'],
            'unitFormDescription' => ['nullable', 'string', 'max:500'],
        ], [], [
            'unitFormTitle' => 'title',
            'unitFormDescription' => 'description',
        ]);

        try {
            $addUnit->handle($this->curriculumId, [
                'title' => $this->unitFormTitle,
                'description' => $this->unitFormDescription !== '' ? $this->unitFormDescription : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('unitFormTitle', (string) (collect($e->errors())->flatten()->first() ?? 'Invalid value.'));

            return;
        } catch (DomainException $e) {
            $this->addError('unitFormTitle', $e->getMessage());

            return;
        }

        $this->reset(['showUnitForm', 'unitFormTitle', 'unitFormDescription']);
        session()->flash('status', 'Unit added.');
    }

    public function openTopicForm(int $unitId): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->topicFormUnitId = $this->topicFormUnitId === $unitId ? null : $unitId;
        $this->topicFormTitle = '';
        $this->topicFormOutcome = '';
    }

    public function saveTopic(AddTopic $addTopic): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        if ($this->topicFormUnitId === null) {
            return;
        }

        $this->validate([
            'topicFormTitle' => ['required', 'string', 'max:160'],
            'topicFormOutcome' => ['nullable', 'string', 'max:500'],
        ], [], [
            'topicFormTitle' => 'title',
            'topicFormOutcome' => 'learning outcome',
        ]);

        try {
            $addTopic->handle($this->topicFormUnitId, [
                'title' => $this->topicFormTitle,
                'learning_outcome' => $this->topicFormOutcome !== '' ? $this->topicFormOutcome : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('topicFormTitle', (string) (collect($e->errors())->flatten()->first() ?? 'Invalid value.'));

            return;
        } catch (DomainException $e) {
            $this->addError('topicFormTitle', $e->getMessage());

            return;
        }

        $this->reset(['topicFormUnitId', 'topicFormTitle', 'topicFormOutcome']);
        session()->flash('status', 'Topic added.');
    }

    public function toggleCompetencyForm(): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->showCompetencyForm = ! $this->showCompetencyForm;
    }

    public function saveCompetency(AddCompetency $addCompetency): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->validate([
            'competencyFormCode' => ['required', 'string', 'max:32'],
            'competencyFormDescriptor' => ['required', 'string', 'max:255'],
        ], [], [
            'competencyFormCode' => 'code',
            'competencyFormDescriptor' => 'descriptor',
        ]);

        try {
            $addCompetency->handle($this->curriculumId, [
                'code' => $this->competencyFormCode,
                'descriptor' => $this->competencyFormDescriptor,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('competencyFormCode', (string) (collect($e->errors())->flatten()->first() ?? 'Invalid value.'));

            return;
        } catch (DomainException $e) {
            $this->addError('competencyFormCode', $e->getMessage());

            return;
        }

        $this->reset(['showCompetencyForm', 'competencyFormCode', 'competencyFormDescriptor']);
        session()->flash('status', 'Competency added.');
    }

    public function openLinkForm(int $topicId): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->linkFormTopicId = $this->linkFormTopicId === $topicId ? null : $topicId;
        $this->linkFormCompetencyId = '';
    }

    public function saveLink(LinkTopicCompetency $linkTopicCompetency): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        if ($this->linkFormTopicId === null) {
            return;
        }

        $this->validate([
            'linkFormCompetencyId' => ['required', 'integer', 'min:1'],
        ], [], [
            'linkFormCompetencyId' => 'competency',
        ]);

        try {
            $linkTopicCompetency->handle(
                $this->linkFormTopicId,
                (int) $this->linkFormCompetencyId,
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('linkFormCompetencyId', $e->getMessage());

            return;
        }

        $this->reset(['linkFormTopicId', 'linkFormCompetencyId']);
        session()->flash('status', 'Competency linked.');
    }

    public function publish(PublishCurriculum $publishCurriculum): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        try {
            $publishCurriculum->handle($this->curriculumId, $this->actor());
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        session()->flash('status', 'Curriculum published. This version is now locked.');
    }

    public function revise(ReviseCurriculum $reviseCurriculum): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        try {
            $draft = $reviseCurriculum->handle($this->curriculumId, $this->actor());
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        $this->redirectRoute('curriculum.show', ['curriculum' => $draft->getKey()]);
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * The header facts: identity names via DB::table (cross-module),
     * publication stamp, publisher name.
     */
    private function header(): stdClass
    {
        /** @var stdClass $row */
        $row = DB::table('curricula as c')
            ->join('subjects as s', 's.id', '=', 'c.subject_id')
            ->join('class_levels as cl', 'cl.id', '=', 'c.class_level_id')
            ->join('academic_years as ay', 'ay.id', '=', 'c.academic_year_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.published_by')
            ->where('c.id', $this->curriculumId)
            ->select([
                'c.id', 'c.title', 'c.description', 'c.sub_system', 'c.version',
                'c.status', 'c.published_at',
                's.name as subject_name', 's.code as subject_code',
                'cl.name as level_name', 'ay.code as year_code',
                'u.name as published_by_name',
            ])
            ->first() ?? new stdClass;

        return $row;
    }

    /**
     * Every version of this curriculum's identity, for the banner's
     * version switcher.
     *
     * @return list<array{id: int, version: int, status: string}>
     */
    private function versions(): array
    {
        /** @var Curriculum $current */
        $current = Curriculum::query()->findOrFail($this->curriculumId);

        $versions = [];

        $rows = Curriculum::query()
            ->where('subject_id', $current->subject_id)
            ->where('class_level_id', $current->class_level_id)
            ->where('academic_year_id', $current->academic_year_id)
            ->where('sub_system', $current->sub_system->value)
            ->orderBy('version')
            ->get(['id', 'version', 'status']);

        foreach ($rows as $row) {
            $versions[] = [
                'id' => (int) $row->getKey(),
                'version' => $row->version,
                'status' => $row->status->value,
            ];
        }

        return $versions;
    }

    public function render(): mixed
    {
        /** @var Curriculum $curriculum */
        $curriculum = Curriculum::query()
            ->with(['units.topics.competencies', 'competencies.topics'])
            ->findOrFail($this->curriculumId);

        return view('livewire.curriculum.show', [
            'curriculum' => $curriculum,
            'header' => $this->header(),
            'versions' => $this->versions(),
            'canManage' => Gate::allows(CurriculumPermission::MANAGE),
        ]);
    }
}
