<?php

declare(strict_types=1);

namespace App\Modules\Communication\Livewire\Outbox;

use App\Modules\Communication\Actions\DispatchOutbox;
use App\Modules\Communication\Actions\RetryFailedMessage;
use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Domain\MessageStatus;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Outbox at /communication/outbox (route to be wired centrally), gated
 * `communication.view`, with every write re-checking `communication.send`
 * in its Action (00-core rule 17: enforced in Actions, not menus).
 *
 * 08-operations 11.1 / 09-ui 2: tabs by state, a KPI strip, filters, and a
 * detail panel that shows the RENDERED body, the attempt count and the last
 * error - the three things an office needs before deciding whether to chase
 * a parent by phone instead.
 *
 * House chrome: x-list-screen + x-kpi-card + x-status-pill. One paginated
 * query per render plus the KPI aggregates; no unbounded collection reaches
 * the view (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which state is showing: queued | sent | failed | disabled | all. */
    #[Url]
    public string $tab = 'queued';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $template = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    /** The message whose detail panel is open, if any. */
    public ?int $selectedId = null;

    public function mount(): void
    {
        Gate::authorize(Permission::CommunicationView->value);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['queued', 'sent', 'failed', 'disabled', 'all'], true) ? $tab : 'queued';
        $this->selectedId = null;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['channel', 'template', 'search']);
        $this->resetPage();
    }

    public function updatedChannel(): void
    {
        $this->resetPage();
    }

    public function updatedTemplate(): void
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

    public function showMessage(int $messageId): void
    {
        Gate::authorize(Permission::CommunicationView->value);

        $this->selectedId = $this->selectedId === $messageId ? null : $messageId;
    }

    public function retry(int $messageId, RetryFailedMessage $retry): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        try {
            $retry->handle($messageId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('retry', $e->getMessage());

            return;
        }

        session()->flash('status', 'Message put back in the queue.');
    }

    public function retryAll(RetryFailedMessage $retry): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        $moved = $retry->all($this->actor());

        session()->flash('status', $moved === 0
            ? 'Nothing was waiting to be retried.'
            : "{$moved} message(s) put back in the queue.");
    }

    /**
     * The "send now" button. The same Action the scheduled command runs, so
     * the office never has to wait for the next cron tick to see the result.
     */
    public function dispatchQueue(DispatchOutbox $dispatch): void
    {
        Gate::authorize(Permission::CommunicationSend->value);

        $tally = $dispatch->handle();

        session()->flash('status', $tally['considered'] === 0
            ? 'The queue was already empty.'
            : sprintf(
                'Driver [%s]: %d sent, %d failed, %d not configured.',
                $tally['driver'],
                $tally['sent'],
                $tally['failed'],
                $tally['disabled'],
            ));

        $this->resetPage();
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::CommunicationView->value);

        return ExcelExport::download(
            'Outbox',
            $this->exportHeaders(),
            $this->exportRows(),
            'outbox-'.$this->tab.'-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::CommunicationView->value);

        return PdfExport::download(
            'Outbox',
            $this->exportHeaders(),
            $this->exportRows(),
            'outbox-'.$this->tab.'-'.now()->format('Y-m-d').'.pdf',
            'landscape',
        );
    }

    /** @return list<string> */
    private function exportHeaders(): array
    {
        return ['Queued at', 'Channel', 'Recipient', 'Language', 'Template', 'Status', 'Attempts', 'Last error'];
    }

    /**
     * The CURRENT filter's rows, capped: an export is still a query, and an
     * outbox on a busy term is not something to load whole.
     *
     * @return list<list<mixed>>
     */
    private function exportRows(): array
    {
        $templates = $this->templateCodes();

        $rows = [];

        foreach ($this->baseQuery()->orderByDesc('queued_at')->limit(5000)->cursor() as $row) {
            $rows[] = [
                $row->queued_at->format('Y-m-d H:i'),
                $row->channel->label(),
                $row->recipient,
                strtoupper($row->language),
                $row->message_template_id === null
                    ? '-'
                    : ($templates[$row->message_template_id] ?? '#'.$row->message_template_id),
                $row->status->label(),
                $row->attempts,
                $row->failure_reason ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return Builder<OutboxMessage>
     */
    private function baseQuery(): Builder
    {
        return OutboxMessage::query()
            ->when($this->tab !== 'all', fn ($q) => $q->where('status', $this->tab))
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->template !== '', fn ($q) => $q->where('message_template_id', (int) $this->template))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('recipient', 'like', '%'.$this->search.'%')
                        ->orWhere('body', 'like', '%'.$this->search.'%')
                        ->orWhere('subject_line', 'like', '%'.$this->search.'%');
                });
            });
    }

    /**
     * @return LengthAwarePaginator<int, OutboxMessage>
     */
    private function rows(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->orderByDesc('queued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Template codes by id, for the filter select and the row labels. The
     * template list is a settings-sized table, not a transaction table.
     *
     * @return array<int, string>
     */
    private function templateCodes(): array
    {
        /** @var array<int, string> $codes */
        $codes = MessageTemplate::query()
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();

        return $codes;
    }

    /**
     * @return array{queued: int, sent: int, failed: int, disabled: int, all: int}
     */
    private function tabCounts(): array
    {
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0];

        $rows = DB::table('outbox_messages')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            /** @var object{status: string, total: int|string} $row */
            if (array_key_exists($row->status, $counts)) {
                $counts[$row->status] = (int) $row->total;
            }
        }

        return [
            'queued' => $counts['queued'],
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'disabled' => $counts['disabled'],
            'all' => array_sum($counts),
        ];
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
        $counts = $this->tabCounts();

        $selected = null;

        if ($this->selectedId !== null) {
            /** @var OutboxMessage|null $selected */
            $selected = OutboxMessage::query()->find($this->selectedId);
        }

        return view('livewire.communication.outbox.index', [
            'rows' => $rows,
            'tabCounts' => $counts,
            'templates' => $this->templateCodes(),
            'selected' => $selected,
            'channels' => MessageChannel::cases(),
            'maxAttempts' => OutboxMessage::MAX_ATTEMPTS,
            'kpis' => [
                'queued' => $counts['queued'],
                'sent' => $counts['sent'],
                'failed' => $counts['failed'],
                'disabled' => $counts['disabled'],
                'total' => $counts['all'],
            ],
            'statusMeta' => [
                MessageStatus::Queued->value => ['label' => MessageStatus::Queued->label(), 'tone' => MessageStatus::Queued->tone()],
                MessageStatus::Sent->value => ['label' => MessageStatus::Sent->label(), 'tone' => MessageStatus::Sent->tone()],
                MessageStatus::Failed->value => ['label' => MessageStatus::Failed->label(), 'tone' => MessageStatus::Failed->tone()],
                MessageStatus::Disabled->value => ['label' => MessageStatus::Disabled->label(), 'tone' => MessageStatus::Disabled->tone()],
            ],
        ]);
    }
}
