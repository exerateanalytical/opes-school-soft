<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\AuditLog;

use App\Modules\Identity\Actions\VerifyAuditChain;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit Log viewer at /audit-log (route wired centrally), gated `audit.view`
 * with export gated `audit.export` - docs/specs/09-ui.md 8.11.
 *
 * The chain, the writer and the verifier were all already built; the spec's
 * complaint was that "an un-viewable audit log satisfies no auditor". This
 * screen is therefore READ-ONLY over `audit_logs` plus one button that calls
 * the EXISTING VerifyAuditChain Action. Nothing here re-implements hashing,
 * and the model itself refuses updates and deletes, so there is no write path
 * to get wrong.
 *
 * The row detail is the point of the screen: `before`/`after` are rendered as
 * a field-by-field change list (added / removed / changed / unchanged) rather
 * than two blobs of JSON, because an auditor reads "fee_amount 25000 ->
 * 15000", not a diff of braces.
 *
 * Eloquent rather than DB::table here on purpose: `before`/`after` are JSON
 * columns and the model's `array` casts are what turn them into arrays. This
 * is the module that owns AuditLog, so the ModuleBoundaryTest rule (never
 * touch ANOTHER module's Models) is not in play.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $module = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $auditableType = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    /** The entry whose before/after diff is expanded, or null. */
    public ?int $selectedId = null;

    /** Verification outcome, only populated after the button is pressed. */
    public bool $chainVerified = false;

    public bool $chainIntact = false;

    public int $chainChecked = 0;

    public ?int $chainBrokenId = null;

    public string $chainReason = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AuditView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['from', 'to', 'module', 'action', 'actor', 'auditableType', 'search']);
        $this->selectedId = null;
        $this->page = 1;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'module', 'action', 'actor', 'auditableType', 'search', 'perPage'], true)) {
            $this->page = 1;
            $this->selectedId = null;
        }
    }

    /** Expand or collapse the diff for one entry. */
    public function toggle(int $id): void
    {
        $this->selectedId = $this->selectedId === $id ? null : $id;
    }

    /**
     * Run the EXISTING verifier. Deliberately not reimplemented here: the
     * anchor comparison in VerifyAuditChain is what makes tail truncation
     * detectable, and a second copy of that logic is a second copy to drift.
     */
    public function verifyChain(VerifyAuditChain $verifyAuditChain): void
    {
        Gate::authorize(Permission::AuditView->value);

        $result = $verifyAuditChain->handle();

        $this->chainVerified = true;
        $this->chainIntact = $result->isIntact();
        $this->chainChecked = $result->checked;
        $this->chainBrokenId = $result->firstBrokenId;
        $this->chainReason = $result->reason ?? '';
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::AuditExport->value);

        return ExcelExport::download(
            'Audit Log',
            $this->exportHeaders(),
            $this->exportRows(),
            'audit-log-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::AuditExport->value);

        return PdfExport::download(
            'Audit Log',
            $this->exportHeaders(),
            $this->exportRows(),
            'audit-log-'.now()->format('Ymd-His').'.pdf',
            'landscape',
        );
    }

    /**
     * @return list<string>
     */
    private function exportHeaders(): array
    {
        return ['ID', 'When', 'Actor', 'Action', 'Module', 'Subject Type', 'Subject ID', 'IP', 'Row Hash'];
    }

    /**
     * Unbounded on purpose and only for the file - it never reaches the view
     * (00-core 6.2 rule 8), matching the eleven report screens.
     *
     * @return list<list<mixed>>
     */
    private function exportRows(): array
    {
        $export = [];

        /** @var AuditLog $entry */
        foreach ($this->query()->get() as $entry) {
            $export[] = [
                $entry->id,
                $entry->created_at?->format('Y-m-d H:i:s') ?? '',
                $entry->actor_name_at_time,
                $entry->action,
                $entry->module,
                self::shortType($entry->auditable_type),
                $entry->auditable_id ?? '',
                $entry->ip ?? '',
                $entry->row_hash,
            ];
        }

        return $export;
    }

    /**
     * @return Builder<AuditLog>
     */
    private function query(): Builder
    {
        return AuditLog::query()
            ->when($this->from !== '', fn (Builder $q): Builder => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q): Builder => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->module !== '', fn (Builder $q): Builder => $q->where('module', $this->module))
            ->when($this->action !== '', fn (Builder $q): Builder => $q->where('action', $this->action))
            ->when($this->actor !== '', fn (Builder $q): Builder => $q->where('actor_id', (int) $this->actor))
            ->when($this->auditableType !== '', fn (Builder $q): Builder => $q->where('auditable_type', $this->auditableType))
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.$this->search.'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('actor_name_at_time', 'like', $term)
                        ->orWhere('auditable_type', 'like', $term)
                        ->orWhere('ip', 'like', $term);
                });
            })
            ->orderByDesc('id');
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    private function rows(): LengthAwarePaginator
    {
        return $this->query()->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return array{entries: int, actors: int, modules: int, today: int, last_entry: string}
     */
    private function kpis(): array
    {
        $last = AuditLog::query()->orderByDesc('id')->value('created_at');

        return [
            'entries' => (int) DB::table('audit_logs')->count(),
            'actors' => (int) DB::table('audit_logs')->distinct()->count('actor_name_at_time'),
            'modules' => (int) DB::table('audit_logs')->distinct()->count('module'),
            'today' => (int) DB::table('audit_logs')->whereDate('created_at', now()->toDateString())->count(),
            'last_entry' => is_string($last) ? $last : (string) $last,
        ];
    }

    /**
     * Distinct values straight from the table rather than a hard-coded list:
     * modules write their own `module` string, and a filter that cannot select
     * a value that exists in the data is worse than no filter.
     *
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        $values = [];

        foreach (DB::table('audit_logs')->select($column)->distinct()->orderBy($column)->get() as $row) {
            /** @var object $row */
            $value = $row->{$column} ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function actorOptions(): array
    {
        $options = [];

        $rows = DB::table('audit_logs')
            ->select('actor_id', 'actor_name_at_time')
            ->whereNotNull('actor_id')
            ->distinct()
            ->orderBy('actor_name_at_time')
            ->get();

        foreach ($rows as $row) {
            /** @var object{actor_id: int|string, actor_name_at_time: string} $row */
            $options[] = ['id' => (int) $row->actor_id, 'name' => (string) $row->actor_name_at_time];
        }

        return $options;
    }

    /** `App\Modules\Fees\Models\Invoice` reads as `Invoice` to an auditor. */
    public static function shortType(?string $type): string
    {
        if ($type === null || $type === '') {
            return '-';
        }

        $position = strrpos($type, '\\');

        return $position === false ? $type : substr($type, $position + 1);
    }

    /**
     * The field-by-field change list. Union of both key sets so a field that
     * only exists on one side is still visible, which is exactly the case an
     * auditor is looking for.
     *
     * @return list<array{field: string, before: string, after: string, changed: bool}>
     */
    private function diff(?AuditLog $entry): array
    {
        if ($entry === null) {
            return [];
        }

        $before = $entry->before ?? [];
        $after = $entry->after ?? [];

        /** @var list<string> $fields */
        $fields = array_values(array_unique(array_merge(
            array_map(static fn (mixed $k): string => (string) $k, array_keys($before)),
            array_map(static fn (mixed $k): string => (string) $k, array_keys($after)),
        )));

        sort($fields, SORT_STRING);

        $diff = [];

        foreach ($fields as $field) {
            $left = self::scalarise($before[$field] ?? null, array_key_exists($field, $before));
            $right = self::scalarise($after[$field] ?? null, array_key_exists($field, $after));

            $diff[] = [
                'field' => $field,
                'before' => $left,
                'after' => $right,
                'changed' => $left !== $right,
            ];
        }

        return $diff;
    }

    /** Render any JSON value as one readable cell. */
    private static function scalarise(mixed $value, bool $present): string
    {
        if (! $present) {
            return '(absent)';
        }

        if ($value === null) {
            return '(null)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '(unencodable)' : $encoded;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '(unreadable)';
    }

    public function render(): mixed
    {
        $selected = $this->selectedId === null
            ? null
            : AuditLog::query()->find($this->selectedId);

        /** @var AuditLog|null $selected */
        return view('livewire.identity.audit-log.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'moduleOptions' => $this->distinctValues('module'),
            'actionOptions' => $this->distinctValues('action'),
            'typeOptions' => $this->distinctValues('auditable_type'),
            'actorOptions' => $this->actorOptions(),
            'selected' => $selected,
            'diff' => $this->diff($selected),
            'canExport' => Gate::allows(Permission::AuditExport->value),
        ]);
    }
}
