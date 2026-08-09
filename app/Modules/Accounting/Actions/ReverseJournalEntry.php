<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/02-accounting.md §9 (C9). Creates a NEW posted entry mirroring
 * every line of the target (debit <-> credit flipped), sets both
 * `reverses_entry_id`/`reversed_by_entry_id`, and never mutates the
 * original's amounts - §9.3's L13 scope is what keeps both halves visible
 * to every statement so they net to zero instead of one half silently
 * vanishing.
 *
 * ---
 * WHICH PERIOD DOES THE REVERSAL POST INTO? §9.2 step 6, quoted exactly,
 * because this is precisely the kind of distinction v1 got wrong elsewhere
 * in this project (00-core's "wrong seeded value" lesson applies to dates
 * too):
 *
 *   "Date the reversal in the earliest open AccountingPeriod - never the
 *    original date. Inheriting the original date posts into a possibly
 *    hard-locked period and, even when open, silently rewrites a month
 *    whose trial balance was already circulated."
 *
 * This is unconditional: it does NOT matter whether the original entry's
 * period is still open or has since been closed. A reversal ever created
 * against the original's own date would let an "adjustment" quietly land
 * in a month whose balance générale has already been printed and handed to
 * the accountant - the entire reason §5.2's two-stage lock and Art. 22's
 * quarterly clôture exist. So: always resolve
 * `AccountingPeriod::firstOpenOnOrAfter(BusinessDate::today())`, never
 * `AccountingPeriod::containing($target->date)`. If the earliest open
 * period starts after today (today's own period is locked), the reversal
 * is dated on that period's `starts_on`, mirroring the §5.4 forward-posting
 * shape - it does not retroactively land on today either, because today
 * simply is not inside any open period.
 * ---
 */
final class ReverseJournalEntry
{
    private const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly SequenceAllocator $sequences,
        private readonly UnletterGroup $unletterGroup,
    ) {}

    public function handle(int $entryId, string $reason, Actor $actor): JournalEntry
    {
        Gate::authorize(Permission::LedgerPost->value);

        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => sprintf('A reversal reason must be at least %d characters.', self::MIN_REASON_LENGTH),
            ]);
        }

        return DB::transaction(function () use ($entryId, $reason, $actor): JournalEntry {
            // §9.2 step 1.
            /** @var JournalEntry $target */
            $target = JournalEntry::query()->whereKey($entryId)->lockForUpdate()->firstOrFail();

            // §9.2 step 3 (also guaranteed by uq_je_reverses/uq_je_reversed_by).
            // Checked BEFORE the generic status guard below: an
            // already-reversed entry's status is itself 'reversed', which
            // the generic check would also reject, but with a message that
            // does not say why - leaving the actor to guess whether the
            // entry was never posted or was already dealt with.
            if ($target->reversed_by_entry_id !== null) {
                throw new DomainException('This entry has already been reversed.');
            }

            // §9.2 step 2.
            if ($target->status !== JournalEntry::STATUS_POSTED) {
                throw new DomainException('Only a posted entry may be reversed.');
            }

            // §9.2 step 4 / L12 - the invariant this agent's brief asks to be
            // tested explicitly: a reversal may not itself be reversed.
            // Reversing a reversal would mean the ORIGINAL was right after
            // all - §9.4 says the correct move is a fresh entry that
            // restates it, with its own justification, not a chain of
            // reversals.
            if ($target->is_reversal) {
                throw new DomainException(
                    'A reversal may not itself be reversed. Reversing a reversal would mean the '
                    .'original entry was right after all - post a fresh entry that restates it instead.'
                );
            }

            $lines = JournalEntryLine::query()
                ->where('journal_entry_id', $target->getKey())
                ->orderBy('sequence')
                ->get();

            // Resolve the target period - always the earliest OPEN period as
            // of today, never the original's own period. See class docblock.
            $today = BusinessDate::today();
            $period = AccountingPeriod::firstOpenOnOrAfter($today);

            if ($period === null) {
                throw new DomainException('No open accounting period exists to post the reversal into.');
            }

            AccountingPeriod::lockForPosting($period->getKey())->assertOpenForPosting();

            $todayCarbon = Carbon::parse($today);
            $periodStart = Carbon::parse((string) $period->starts_on);
            $periodEnd = Carbon::parse((string) $period->ends_on);

            $todayInsidePeriod = $todayCarbon->greaterThanOrEqualTo($periodStart)
                && $todayCarbon->lessThanOrEqualTo($periodEnd);

            $reversalDate = $todayInsidePeriod ? $todayCarbon : $periodStart;

            $fiscalYearId = (int) $period->fiscal_year_id;
            $academicYearId = AccountingPeriod::resolveAcademicYearId($reversalDate);

            /** @var Journal $journal */
            $journal = Journal::query()->findOrFail($target->journal_id);
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

            // §9.2 step 9 - a fresh piece_no, gapless per (journal, fiscal
            // year), allocated inside this transaction (00-core §12 / L7).
            // SAME series as PostJournalEntry - a reversal continues the
            // journal's numbering, it does not run a parallel counter.
            $series = sprintf('journal_entry_piece.%d.%d', $journal->getKey(), $fiscalYearId);
            $sequenceNumber = $this->sequences->allocate($series);
            $pieceNo = $this->renderPieceNo($journal->piece_no_format, $journal->code, $fiscalYear->code, $sequenceNumber);

            $totalDebit = Money::zero();
            $totalCredit = Money::zero();
            foreach ($lines as $line) {
                $totalDebit = $totalDebit->plus(Money::of((int) $line->credit));
                $totalCredit = $totalCredit->plus(Money::of((int) $line->debit));
            }

            // L3's trigger refuses a line insert once the parent is posted
            // (docs/specs/02-accounting.md 4.3), so the reversal is created
            // `draft` first, its lines go in while that is still legal, and
            // only then does it flip to `posted` below - the same order
            // DraftJournalEntry/PostJournalEntry use for an ordinary entry.
            $reversal = JournalEntry::query()->create([
                'journal_id' => $journal->getKey(),
                'piece_no' => null,
                'date' => $reversalDate->toDateString(),
                // §9.2 step 7: value_date is the BUSINESS DATE of the
                // reversal itself, never the original's.
                'value_date' => $today,
                'accounting_period_id' => $period->getKey(),
                'fiscal_year_id' => $fiscalYearId,
                'academic_year_id' => $academicYearId,
                'label' => sprintf('Reversal of %s', $target->label),
                'reference' => $target->reference,
                'status' => JournalEntry::STATUS_DRAFT,
                'source_type' => $target->source_type,
                'source_id' => $target->source_id,
                'reverses_entry_id' => $target->getKey(),
                'reversal_reason' => $reason,
                'is_migration' => false,
                'is_forward_posted' => ! $todayInsidePeriod,
                'total_debit' => 0,
                'total_credit' => 0,
                'created_by' => $actor->id,
            ]);

            // §9.2 step 8: mirror every line, debit <-> credit flipped,
            // PRESERVING partner_type/partner_id, tax_code_id, due_date and
            // (when analytic splits land in a later phase) analytic splits -
            // a reversal that drops the partner breaks L9.
            foreach ($lines as $line) {
                JournalEntryLine::query()->create([
                    'journal_entry_id' => $reversal->getKey(),
                    'sequence' => $line->sequence,
                    'account_id' => $line->account_id,
                    'label' => $line->label,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'partner_type' => $line->partner_type,
                    'partner_id' => $line->partner_id,
                    // Deliberately NOT copied: `lettering_id` would attach
                    // the mirrored line to the ORIGINAL's lettering group,
                    // which step 11 is about to unletter anyway.
                    'lettering_id' => null,
                    'tax_code_id' => $line->tax_code_id,
                    'tax_base_amount' => $line->tax_base_amount,
                    'due_date' => $line->due_date,
                    'source_line_type' => $line->source_line_type,
                    'source_line_id' => $line->source_line_id,
                ]);
            }

            $reversal->forceFill([
                'status' => JournalEntry::STATUS_POSTED,
                'piece_no' => $pieceNo,
                'total_debit' => $totalDebit->amount(),
                'total_credit' => $totalCredit->amount(),
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            // §9.2 step 10.
            $target->forceFill([
                'reversed_by_entry_id' => $reversal->getKey(),
                'status' => JournalEntry::STATUS_REVERSED,
            ])->save();

            // §9.2 step 11: unletter any group the target's lines belonged
            // to, reason "reversal".
            $letteringIds = $lines->pluck('lettering_id')->filter()->unique()->values();
            foreach ($letteringIds as $letteringId) {
                $this->unletterGroup->handle((int) $letteringId, 'reversal', $actor);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $reversal->getKey(),
                after: [
                    'reverses_entry_id' => $target->getKey(),
                    'reversal_reason' => $reason,
                    'piece_no' => $pieceNo,
                    'total_debit' => $totalDebit->amount(),
                    'total_credit' => $totalCredit->amount(),
                ],
                actor: $actor,
            );

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $target->getKey(),
                after: ['status' => JournalEntry::STATUS_REVERSED, 'reversed_by_entry_id' => $reversal->getKey()],
                actor: $actor,
            );

            // §9.2 step 12 says to emit JournalEntryReversed after commit so
            // the source module can react (re-open an invoice line, un-clear
            // a payment). No Events file is in this agent's file ownership
            // list, so this is left for the integrator - see this agent's
            // final report.

            return $reversal->refresh();
        });
    }

    /**
     * Supported placeholders: `{journal}`, `{fy}`, `{seq}` (zero-padded to
     * 6), `{seq:N}` for another width - mirrors
     * App\Modules\Students\Actions\CreateStudent's `render()` convention
     * for the same kind of templated identifier.
     */
    private function renderPieceNo(string $format, string $journalCode, string $fiscalYearCode, int $sequence): string
    {
        $rendered = preg_replace_callback(
            '/\{seq:(\d+)\}/',
            static fn (array $m): string => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT),
            $format,
        ) ?? $format;

        $rendered = str_replace('{seq}', str_pad((string) $sequence, 6, '0', STR_PAD_LEFT), $rendered);
        $rendered = str_replace('{journal}', $journalCode, $rendered);

        return str_replace('{fy}', $fiscalYearCode, $rendered);
    }
}
