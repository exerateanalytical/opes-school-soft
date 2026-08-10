<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Flips a `draft` JournalEntry to `posted`. docs/specs/02-accounting.md
 * §4.1/§4.3.
 *
 * Order of operations inside the ONE transaction, and why:
 *
 *  1. `SELECT ... FOR UPDATE` the JournalEntry row. Everything else in this
 *     Action depends on nothing else being able to add/remove a line or
 *     post the same entry twice while we work - AddJournalEntryLine takes
 *     the same exclusive lock before writing, so the two Actions serialise
 *     against each other.
 *  2. Refuse unless `status = draft`.
 *  3. Sum the (now-frozen-for-us) lines with `Money`, under the lock, and
 *     assert debit = credit - L2's primary mechanism, stated in the spec as
 *     exactly this: computed in PHP, asserted under FOR UPDATE, before
 *     commit.
 *  4. L5: lock the `AccountingPeriod` row (D2's `lockForPosting()`, a SHARED
 *     lock) and call `assertOpenForPosting()`. This is the period the entry
 *     already carries - it was derived from `date` at draft time and is
 *     immutable from here (L4), so there is nothing to re-derive.
 *  5. L7: allocate `piece_no` via the existing `SequenceAllocator`, scoped
 *     `(journal_id, fiscal_year_id)`, ONLY here - never at draft creation.
 *     The allocator enforces "must run inside a transaction" itself; if
 *     this Action's transaction rolls back after allocating (e.g. because a
 *     later step throws), the row-locked `next_value` UPDATE rolls back
 *     with it and the number is never issued to anyone - proven end-to-end
 *     in LedgerInvariantsTest, not just asserted of the allocator in
 *     isolation.
 *  6. Stamp `posted`, `posted_by`, `posted_at`, and the now-equal
 *     `total_debit`/`total_credit` (satisfying `ck_je_totals`, which held
 *     trivially at 0/0 while still draft).
 */
final class PostJournalEntry
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly SequenceAllocator $sequences,
    ) {
    }

    public function handle(int $journalEntryId, Actor $actor, bool $allowSoftLocked = false): JournalEntry
    {
        Gate::authorize(Permission::LedgerPost->value);

        return DB::transaction(function () use ($journalEntryId, $actor, $allowSoftLocked): JournalEntry {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::query()->whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status !== JournalEntry::STATUS_DRAFT) {
                throw new DomainException(
                    "Journal entry {$entry->getKey()} is {$entry->status}; only a draft entry may be posted."
                );
            }

            $lines = $entry->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Journal entry {$entry->getKey()} has no lines to post.");
            }

            $totalDebit = Money::sum($lines->map(fn (object $line): Money => Money::of((int) $line->debit)));
            $totalCredit = Money::sum($lines->map(fn (object $line): Money => Money::of((int) $line->credit)));

            if (! $totalDebit->equals($totalCredit)) {
                throw new DomainException(sprintf(
                    'Journal entry %d does not balance (L2): debit %s <> credit %s.',
                    (int) $entry->getKey(),
                    $totalDebit->format(),
                    $totalCredit->format(),
                ));
            }

            // Budget control (02-accounting §16), deliberately HERE: the
            // lines are already frozen under FOR UPDATE and the entry is
            // known to balance, but no gapless piece_no has been allocated
            // yet - so a refusal rolls back without burning a number out of
            // the sequence.
            //
            // This is a no-op unless an account carries budget_control other
            // than `none` AND the fiscal year has an approved, current
            // budget; a draft budget can never refuse a posting.
            // PostFromEvent needs no change - it posts through here.
            app(AssertWithinBudget::class)->handle(
                $lines->all(),
                (string) $entry->date,
                (int) $entry->fiscal_year_id,
            );

            // L5.
            $period = AccountingPeriod::lockForPosting($entry->accounting_period_id);
            $period->assertOpenForPosting($allowSoftLocked);

            // L7. Scope is deliberately (journal, fiscal_year), matching the
            // spec's uq_je_piece key exactly, and allocated here - at
            // posting - never in DraftJournalEntry.
            $series = "journal_entry_piece.{$entry->journal_id}.{$entry->fiscal_year_id}";
            $number = $this->sequences->allocate($series);

            /** @var Journal $journal */
            $journal = Journal::query()->findOrFail($entry->journal_id);
            $fiscalYearCode = DB::table('fiscal_years')->where('id', $entry->fiscal_year_id)->value('code');

            // A simplified rendering of Journal.piece_no_format's
            // `{journal}/{fy}/{seq:6}` shape. Parsing the templated format
            // string itself belongs to the same whitelisted-grammar engine
            // as PostingRule expressions (02-accounting.md §11.1), which is
            // not this agent's scope; the gapless number this produces is
            // what L7 actually requires.
            $pieceNo = sprintf('%s/%s/%06d', $journal->code, (string) $fiscalYearCode, $number);

            $entry->forceFill([
                'piece_no' => $pieceNo,
                'status' => JournalEntry::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'total_debit' => $totalDebit->amount(),
                'total_credit' => $totalCredit->amount(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $entry->getKey(),
                after: [
                    'status' => JournalEntry::STATUS_POSTED,
                    'piece_no' => $pieceNo,
                    'total_debit' => $totalDebit->amount(),
                    'total_credit' => $totalCredit->amount(),
                ],
                actor: $actor,
            );

            return $entry->fresh(['lines']) ?? $entry;
        });
    }
}
