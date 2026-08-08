<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\JournalEntries;

use App\Modules\Accounting\Actions\AddJournalEntryLine;
use App\Modules\Accounting\Actions\DraftJournalEntry;
use App\Modules\Accounting\Actions\PostJournalEntry;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Create (and continue) a journal entry, docs/specs/02-accounting.md §4.1.
 * Routed at /ledger/journal-entries/create (routes/web.php,
 * `ledger.journal-entries.create`, gated `can:ledger.post`).
 *
 * Two Actions build a draft, and this screen uses each for what it is:
 *
 *  - `DraftJournalEntry::handle()` creates the entry AND its lines in one
 *    call, so a brand-new entry is composed entirely in this component's
 *    state (free add/remove/edit of `$lines`) and only written once, on
 *    "Save draft".
 *  - `AddJournalEntryLine::handle()` appends one line to an EXISTING draft.
 *    Once an entry exists (`$draftEntryId` set, whether just created or
 *    loaded via `?entry=`), there is no Action to edit its header or to
 *    remove/edit an already-persisted line - only to append. This component
 *    honours that: header fields lock once the entry exists, and only
 *    not-yet-persisted rows can be removed. Inventing a bulk "replace lines"
 *    call the domain layer does not offer would be UI writing to the ledger
 *    a rule Accounting itself never agreed to.
 *
 * The live Σdebit/Σcredit readout is computed in `render()` from
 * `$lines` on every keystroke (`wire:model.live` on the amount inputs), so
 * an imbalance is visible before Post is ever clicked - not discovered from
 * `PostJournalEntry`'s L2 exception after the fact.
 */
#[Layout('layouts.app')]
final class Form extends Component
{
    #[Url]
    public string $entry = '';

    public ?int $draftEntryId = null;

    public string $pieceNo = '';

    public string $journalId = '';

    public string $date = '';

    public string $valueDate = '';

    public string $label = '';

    public string $reference = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $accountQuery = [];

    public string $errorMessage = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $this->date = now()->toDateString();

        if ($this->entry !== '') {
            $this->loadExistingDraft((int) $this->entry);
        }

        if ($this->lines === []) {
            $this->lines = [self::blankLine(), self::blankLine()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankLine(): array
    {
        return [
            'id' => null,
            'account_id' => '',
            'account_label' => '',
            'label' => '',
            'debit' => '',
            'credit' => '',
            'partner_type' => '',
            'partner_id' => '',
        ];
    }

    private function loadExistingDraft(int $entryId): void
    {
        /** @var JournalEntry $found */
        $found = JournalEntry::query()->with('lines')->findOrFail($entryId);

        if ($found->status !== JournalEntry::STATUS_DRAFT) {
            $this->errorMessage = __('opes.ledger_screen.je_form_not_draft');

            return;
        }

        $this->draftEntryId = (int) $found->getKey();
        $this->pieceNo = (string) $found->piece_no;
        $this->journalId = (string) $found->journal_id;
        $this->date = $found->date->toDateString();
        $this->valueDate = $found->value_date->toDateString();
        $this->label = $found->label;
        $this->reference = (string) $found->reference;

        $accounts = ChartOfAccount::query()->whereIn('id', $found->lines->pluck('account_id'))->get()->keyBy('id');

        $this->lines = $found->lines->map(fn (JournalEntryLine $line): array => [
            'id' => $line->id,
            'account_id' => (string) $line->account_id,
            'account_label' => self::accountLabel($accounts->get($line->account_id)),
            'label' => $line->label,
            'debit' => $line->debit > 0 ? (string) $line->debit : '',
            'credit' => $line->credit > 0 ? (string) $line->credit : '',
            // Not the nullsafe/coalesce form: PHPStan flagged that as
            // "always non-null" here (a known Larastan quirk with enum
            // casts), but partner_type genuinely is null for any line on a
            // non-collective account - the common case, not the exception -
            // so an explicit null check is what actually stays safe if that
            // inference is wrong, and it satisfies PHPStan either way.
            'partner_type' => $line->partner_type === null ? '' : $line->partner_type->value,
            'partner_id' => (string) $line->partner_id,
        ])->all();
    }

    private static function accountLabel(?ChartOfAccount $account): string
    {
        if ($account === null) {
            return '';
        }

        return $account->code.' — '.($account->display_alias ?? $account->name);
    }

    public function addLine(): void
    {
        $this->lines[] = self::blankLine();
    }

    public function removeLine(int $index): void
    {
        // Only a not-yet-persisted row can be dropped here (see class
        // docblock): a persisted line has no "remove" Action to call.
        if (($this->lines[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines = [self::blankLine()];
        }
    }

    public function pickAccount(int $index, int $accountId): void
    {
        $account = ChartOfAccount::query()->find($accountId);

        if ($account === null) {
            return;
        }

        $this->lines[$index]['account_id'] = (string) $account->id;
        $this->lines[$index]['account_label'] = self::accountLabel($account);
        $this->accountQuery[$index] = '';

        if (! $account->is_collective) {
            $this->lines[$index]['partner_type'] = '';
            $this->lines[$index]['partner_id'] = '';
        }
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public function accountMatches(int $index): Collection
    {
        $needle = mb_strtolower(trim($this->accountQuery[$index] ?? ''));

        if ($needle === '') {
            return collect();
        }

        return ChartOfAccount::query()
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->where(function ($query) use ($needle): void {
                $query->where('code', 'like', $needle.'%')
                    ->orWhere('name', 'like', '%'.$needle.'%')
                    ->orWhere('name_fr', 'like', '%'.$needle.'%');
            })
            ->orderBy('code')
            ->limit(8)
            ->get();
    }

    private function actor(): Actor
    {
        // No `Identity\Models\User` import here on purpose - crossing the
        // module boundary via that Model is exactly what
        // tests/Architecture/ModuleBoundaryTest.php forbids (00-core 6.2).
        // `auth()->user()` types as the framework's Authenticatable
        // contract; `Actor::system()` is the same shared-kernel fallback
        // every other module's Actions use when there is no authenticated
        // actor.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }

    /**
     * @return array<int, array{account_id: int, label: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}>
     */
    private function pendingLinesPayload(): array
    {
        $payload = [];

        foreach ($this->lines as $line) {
            if (($line['id'] ?? null) !== null) {
                continue; // already persisted
            }

            if ($line['account_id'] === '' && $line['debit'] === '' && $line['credit'] === '') {
                continue; // untouched blank row
            }

            $payload[] = [
                'account_id' => (int) $line['account_id'],
                'label' => (string) ($line['label'] !== '' ? $line['label'] : $this->label),
                'debit' => (int) ($line['debit'] !== '' ? $line['debit'] : 0),
                'credit' => (int) ($line['credit'] !== '' ? $line['credit'] : 0),
                'partner_type' => $line['partner_type'] !== '' ? (string) $line['partner_type'] : null,
                'partner_id' => $line['partner_id'] !== '' ? (int) $line['partner_id'] : null,
            ];
        }

        return $payload;
    }

    public function saveDraft(DraftJournalEntry $draftJournalEntry, AddJournalEntryLine $addLine): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $this->errorMessage = '';

        $validated = $this->validate([
            'journalId' => ['required'],
            'date' => ['required', 'date'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        try {
            $newLines = $this->pendingLinesPayload();

            if ($this->draftEntryId === null) {
                $entry = $draftJournalEntry->handle(
                    journalId: (int) $validated['journalId'],
                    date: $validated['date'],
                    valueDate: $this->valueDate !== '' ? $this->valueDate : null,
                    label: $validated['label'],
                    reference: $this->reference !== '' ? $this->reference : null,
                    lines: $newLines,
                    actor: $this->actor(),
                );

                $this->draftEntryId = (int) $entry->getKey();
            } else {
                foreach ($newLines as $line) {
                    $addLine->handle(
                        journalEntryId: $this->draftEntryId,
                        accountId: $line['account_id'],
                        label: $line['label'],
                        debit: $line['debit'],
                        credit: $line['credit'],
                        actor: $this->actor(),
                        partnerType: $line['partner_type'],
                        partnerId: $line['partner_id'],
                    );
                }
            }

            $this->loadExistingDraft($this->draftEntryId);
            $this->statusMessage = __('opes.ledger_screen.je_draft_saved');
        } catch (DomainException $e) {
            // A legible inline message, never a raw stack trace (task
            // brief) - imbalance, wrong status, whatever the Action refused.
            $this->errorMessage = $e->getMessage();
        }
    }

    public function post(PostJournalEntry $postJournalEntry): mixed
    {
        Gate::authorize(Permission::LedgerPost->value);

        $this->errorMessage = '';

        if ($this->draftEntryId === null) {
            $this->errorMessage = __('opes.ledger_screen.je_form_save_before_post');

            return null;
        }

        if ($this->pendingLinesPayload() !== []) {
            $this->errorMessage = __('opes.ledger_screen.je_form_unsaved_lines');

            return null;
        }

        try {
            $postJournalEntry->handle($this->draftEntryId, $this->actor());

            session()->flash('status', __('opes.ledger_screen.je_posted'));

            return $this->redirect(route('ledger.journal-entries.index'), navigate: false);
        } catch (DomainException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    /**
     * @return Collection<int, Journal>
     */
    private function journalOptions(): Collection
    {
        return Journal::query()->where('is_active', true)->where('is_archived', false)->orderBy('code')->get();
    }

    /**
     * @return array{debit: int, credit: int}
     */
    private function runningTotals(): array
    {
        $debit = 0;
        $credit = 0;

        foreach ($this->lines as $line) {
            $debit += (int) ($line['debit'] !== '' ? $line['debit'] : 0);
            $credit += (int) ($line['credit'] !== '' ? $line['credit'] : 0);
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    public function render(): mixed
    {
        $totals = $this->runningTotals();

        // Postable accounts whose id is already picked on a line, keyed for
        // the view's is_collective / allowed_partner_types lookup, so the
        // partner fields are driven by the account's real flag - never a
        // hardcoded list of "which accounts are collective" (task brief).
        $pickedAccountIds = collect($this->lines)->pluck('account_id')->filter(fn ($id) => $id !== '')->map(fn ($id) => (int) $id);
        $pickedAccounts = ChartOfAccount::query()->whereIn('id', $pickedAccountIds)->get()->keyBy('id');

        return view('livewire.accounting.journal-entries.form', [
            'journalOptions' => $this->journalOptions(),
            'pickedAccounts' => $pickedAccounts,
            'totalDebit' => $totals['debit'],
            'totalCredit' => $totals['credit'],
            'isBalanced' => $totals['debit'] === $totals['credit'] && $totals['debit'] > 0,
            'isLocked' => $this->draftEntryId !== null,
        ]);
    }
}
