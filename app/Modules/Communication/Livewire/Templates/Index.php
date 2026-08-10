<?php

declare(strict_types=1);

namespace App\Modules\Communication\Livewire\Templates;

use App\Modules\Communication\Actions\SaveMessageTemplate;
use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Support\MergeFields;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Support\ExcelExport;
use App\Support\Audit\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Message templates at /communication/templates (route to be wired
 * centrally), gated `communication.view`; saving re-checks
 * `communication.send` inside SaveMessageTemplate.
 *
 * Toggle-form pattern, exactly as Welfare\Visitors: the create/edit form is
 * an inline panel above the list rather than a second route, because a
 * template is a settings object an office edits three times a year.
 *
 * Both language bodies are required by the schema, so the form asks for both
 * side by side rather than hiding French behind a tab where it gets
 * forgotten.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $channel = '';

    /** all | active | inactive. */
    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Create / edit form ──────────────────────────────────────────────
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $formCode = '';

    public string $formName = '';

    public string $formNameFr = '';

    public string $formChannel = 'sms';

    public string $formSubjectEn = '';

    public string $formSubjectFr = '';

    public string $formBodyEn = '';

    public string $formBodyFr = '';

    /** Comma or space separated placeholder names. */
    public string $formVariables = '';

    public bool $formActive = true;

    public function mount(): void
    {
        Gate::authorize(Permission::CommunicationView->value);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'active', 'inactive'], true) ? $tab : 'all';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['channel', 'search']);
        $this->resetPage();
    }

    public function updatedChannel(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleForm(): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        $this->showForm = ! $this->showForm;

        if (! $this->showForm) {
            $this->clearForm();
        }
    }

    public function edit(int $templateId): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        /** @var MessageTemplate|null $template */
        $template = MessageTemplate::query()->find($templateId);

        if ($template === null) {
            $this->addError('formCode', 'That template no longer exists.');

            return;
        }

        $this->editingId = (int) $template->getKey();
        $this->formCode = $template->code;
        $this->formName = $template->name;
        $this->formNameFr = $template->name_fr;
        $this->formChannel = $template->channel->value;
        $this->formSubjectEn = (string) $template->subject_en;
        $this->formSubjectFr = (string) $template->subject_fr;
        $this->formBodyEn = $template->body_en;
        $this->formBodyFr = $template->body_fr;
        $this->formVariables = implode(', ', $template->declaredVariables());
        $this->formActive = $template->is_active;
        $this->showForm = true;
    }

    /** Fill the declared-variables box from what the bodies actually use. */
    public function detectVariables(): void
    {
        $found = array_values(array_unique(array_merge(
            MergeFields::extract($this->formBodyEn),
            MergeFields::extract($this->formBodyFr),
            MergeFields::extract($this->formSubjectEn),
            MergeFields::extract($this->formSubjectFr),
        )));

        $this->formVariables = implode(', ', $found);
    }

    public function save(SaveMessageTemplate $save): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        try {
            $save->handle($this->editingId, [
                'code' => $this->formCode,
                'name' => $this->formName,
                'name_fr' => $this->formNameFr,
                'channel' => $this->formChannel,
                'subject_en' => $this->formSubjectEn,
                'subject_fr' => $this->formSubjectFr,
                'body_en' => $this->formBodyEn,
                'body_fr' => $this->formBodyFr,
                'variables' => $this->formVariables,
                'is_active' => $this->formActive,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form'.str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        }

        $wasEditing = $this->editingId !== null;
        $this->clearForm();
        $this->showForm = false;
        session()->flash('status', $wasEditing ? 'Template updated.' : 'Template created.');
    }

    /**
     * Deactivate rather than delete: a template with sent history is not
     * deletable (the 300004 FK is RESTRICT), and the outbox's audit trail
     * depends on the row surviving.
     */
    public function toggleActive(int $templateId, SaveMessageTemplate $save): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        /** @var MessageTemplate|null $template */
        $template = MessageTemplate::query()->find($templateId);

        if ($template === null) {
            return;
        }

        try {
            $save->handle((int) $template->getKey(), ['is_active' => ! $template->is_active], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('formCode', (string) ($e->errors()[array_key_first($e->errors())][0] ?? 'Could not update.'));

            return;
        }

        session()->flash('status', $template->is_active ? 'Template deactivated.' : 'Template activated.');
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::CommunicationView->value);

        $rows = [];

        foreach ($this->baseQuery()->orderBy('code')->limit(2000)->cursor() as $template) {
            $rows[] = [
                $template->code,
                $template->name,
                $template->name_fr,
                $template->channel->label(),
                implode(', ', $template->declaredVariables()),
                $template->is_active ? 'Active' : 'Inactive',
            ];
        }

        return ExcelExport::download(
            'Message Templates',
            ['Code', 'Name (EN)', 'Name (FR)', 'Channel', 'Variables', 'Status'],
            $rows,
            'message-templates-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    private function clearForm(): void
    {
        $this->reset([
            'editingId', 'formCode', 'formName', 'formNameFr', 'formSubjectEn',
            'formSubjectFr', 'formBodyEn', 'formBodyFr', 'formVariables',
        ]);
        $this->formChannel = 'sms';
        $this->formActive = true;
        $this->resetErrorBag();
    }

    /**
     * @return Builder<MessageTemplate>
     */
    private function baseQuery(): Builder
    {
        return MessageTemplate::query()
            ->when($this->tab === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->tab === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('name_fr', 'like', '%'.$this->search.'%');
                });
            });
    }

    /**
     * @return LengthAwarePaginator<int, MessageTemplate>
     */
    private function rows(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->orderBy('code')
            ->paginate($this->perPage, page: $this->page);
    }

    private function actor(): Actor
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function render(): mixed
    {
        $rows = $this->rows();

        $total = (int) DB::table('message_templates')->count();
        $active = (int) DB::table('message_templates')->where('is_active', true)->count();

        /** @var array<int, int> $usage */
        $usage = DB::table('outbox_messages')
            ->whereNotNull('message_template_id')
            ->selectRaw('message_template_id, COUNT(*) AS total')
            ->groupBy('message_template_id')
            ->pluck('total', 'message_template_id')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        return view('livewire.communication.templates.index', [
            'rows' => $rows,
            'channels' => MessageChannel::cases(),
            'usage' => $usage,
            'tabCounts' => [
                'all' => $total,
                'active' => $active,
                'inactive' => $total - $active,
            ],
            'kpis' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'messages' => (int) DB::table('outbox_messages')->whereNotNull('message_template_id')->count(),
            ],
        ]);
    }
}
