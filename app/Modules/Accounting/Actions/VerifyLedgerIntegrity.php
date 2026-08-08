<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Lettering;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger's independent conscience - the "backstop" column of
 * docs/specs/02-accounting.md §4.3's invariant table, run nightly. Every
 * invariant below is *supposed* to be structurally impossible to violate:
 * L2 is asserted in-Action under FOR UPDATE, L8 is a BEFORE INSERT/UPDATE
 * trigger, L7 is a locked sequence row. This job re-asserts them anyway,
 * because a trigger someone dropped in production is silent, and the whole
 * point of a backstop is to catch what the primary mechanism missed.
 *
 * Findings are returned keyed by invariant code; an empty list under a key
 * means that invariant is healthy. L11's tables (`analytic_axes` /
 * `journal_entry_line_analytics`) are being built by a concurrent work
 * package, so that section is schema-guarded via `Schema::hasTable` - the
 * same defensive pattern `ArchiveAccount` uses - and reports finding-free
 * until the tables exist.
 *
 * Read-mostly, gated on `ledger.view` only when an authenticated user is
 * present. From the scheduler / console there is no user; the nightly run
 * must not die on a Gate that exists to keep *people* out of ledger
 * screens, and `ReconcileAuxiliaryBalances` (which L9 delegates to)
 * unconditionally authorizes `ledger.view`, so an unattended run installs a
 * guest-only allowance for that single ability. It grants nothing to any
 * real user ($user !== null falls through to the normal Spatie resolution)
 * and every ledger HTTP route sits behind `auth` middleware, so no guest
 * request can reach a `ledger.view` authorization anyway.
 *
 * The one write is L10's downgrade (full group that no longer nets to zero
 * becomes `partial`, per the table: "Nightly job downgrades a violating
 * group to partial and alarms") - audited through WriteAuditEntry, with
 * Actor::system() when unattended.
 */
final class VerifyLedgerIntegrity
{
    public const INVARIANTS = ['L2', 'L5', 'L7', 'L8', 'L9', 'L10', 'L11'];

    public function __construct(
        private readonly ReconcileAuxiliaryBalances $reconcile,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @return array<string, list<array<string, int|string>>>
     */
    public function handle(?int $fiscalYearId = null): array
    {
        if (auth()->check()) {
            Gate::authorize(Permission::LedgerView->value);
        } else {
            $this->allowUnattendedRun();
        }

        return [
            'L2' => $this->l2EntriesOutOfBalance($fiscalYearId),
            'L5' => $this->l5PostedIntoClosedPeriod($fiscalYearId),
            'L7' => $this->l7SequenceGaps($fiscalYearId),
            'L8' => $this->l8PartnerDiscipline($fiscalYearId),
            'L9' => $this->l9AuxiliaryReconciliation(),
            'L10' => $this->l10LetteringGroups(),
            'L11' => $this->l11AnalyticSplits($fiscalYearId),
        ];
    }

    /**
     * See the class docblock. Registered at most once per process; the
     * closure allows guests (and ONLY guests) the single `ledger.view`
     * ability, returning null for every other combination so normal
     * resolution is untouched.
     */
    private function allowUnattendedRun(): void
    {
        // Container-scoped, not a PHP static: the test harness rebuilds the
        // application (and its Gate) per test, and a static that survives the
        // rebuild would skip re-registering on the new Gate instance.
        if (app()->bound('opes.ledger.unattended_gate')) {
            return;
        }

        app()->instance('opes.ledger.unattended_gate', true);

        Gate::before(static function (?object $user, string $ability): ?bool {
            return $user === null && $ability === Permission::LedgerView->value ? true : null;
        });
    }

    /**
     * L2 backstop (b): entries where Σ lines ≠ the denormalised totals, or
     * total_debit ≠ total_credit. Drafts are excluded - a draft is allowed
     * to be mid-edit and unbalanced; only posted/reversed entries have
     * sworn to L2.
     *
     * @return list<array<string, int|string>>
     */
    private function l2EntriesOutOfBalance(?int $fiscalYearId): array
    {
        $rows = DB::table('journal_entries as e')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($fiscalYearId !== null, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->groupBy('e.id', 'e.piece_no', 'e.total_debit', 'e.total_credit')
            ->havingRaw(
                'COALESCE(SUM(l.debit), 0) <> e.total_debit'
                .' OR COALESCE(SUM(l.credit), 0) <> e.total_credit'
                .' OR e.total_debit <> e.total_credit'
            )
            ->selectRaw(
                'e.id, e.piece_no, e.total_debit, e.total_credit,'
                .' COALESCE(SUM(l.debit), 0) as line_debit,'
                .' COALESCE(SUM(l.credit), 0) as line_credit'
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'entry_id' => (int) $row->id,
                'piece_no' => (string) $row->piece_no,
                'total_debit' => (int) $row->total_debit,
                'total_credit' => (int) $row->total_credit,
                'line_debit' => (int) $row->line_debit,
                'line_credit' => (int) $row->line_credit,
            ];
        }

        return $findings;
    }

    /**
     * L5 backstop: no posted entry may reference a period that was already
     * hard-locked when the posting happened. `hard_locked_at` is this
     * schema's "closed_at" - soft lock still admits privileged postings by
     * design (§5.4), so soft_locked_at proves nothing.
     *
     * @return list<array<string, int|string>>
     */
    private function l5PostedIntoClosedPeriod(?int $fiscalYearId): array
    {
        $rows = DB::table('journal_entries as e')
            ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('e.status', JournalEntry::STATUS_POSTED)
            ->when($fiscalYearId !== null, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->whereNotNull('p.hard_locked_at')
            ->whereNotNull('e.posted_at')
            ->whereColumn('p.hard_locked_at', '<', 'e.posted_at')
            ->select('e.id', 'e.piece_no', 'e.posted_at', 'p.id as period_id', 'p.hard_locked_at')
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'entry_id' => (int) $row->id,
                'piece_no' => (string) $row->piece_no,
                'posted_at' => (string) $row->posted_at,
                'period_id' => (int) $row->period_id,
                'hard_locked_at' => (string) $row->hard_locked_at,
            ];
        }

        return $findings;
    }

    /**
     * L7 backstop - the `SequenceGap` report (00-core §12): per
     * (journal, fiscal_year), COUNT(*) must equal MAX(sequence part of
     * piece_no). The format is `{journal}/{fy}/{seq:6}`
     * (see PostJournalEntry), so the numeric tail is everything after the
     * last '/'.
     *
     * @return list<array<string, int|string>>
     */
    private function l7SequenceGaps(?int $fiscalYearId): array
    {
        $rows = DB::table('journal_entries as e')
            ->whereNotNull('e.piece_no')
            ->when($fiscalYearId !== null, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->groupBy('e.journal_id', 'e.fiscal_year_id')
            ->havingRaw("COUNT(*) <> MAX(CAST(SUBSTRING_INDEX(e.piece_no, '/', -1) AS UNSIGNED))")
            ->selectRaw(
                'e.journal_id, e.fiscal_year_id, COUNT(*) as entry_count,'
                ." MAX(CAST(SUBSTRING_INDEX(e.piece_no, '/', -1) AS UNSIGNED)) as max_sequence"
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'journal_id' => (int) $row->journal_id,
                'fiscal_year_id' => (int) $row->fiscal_year_id,
                'entry_count' => (int) $row->entry_count,
                'max_sequence' => (int) $row->max_sequence,
            ];
        }

        return $findings;
    }

    /**
     * L8 backstop: a line on a collective account must carry a partner, a
     * line on a non-collective account must not. The BEFORE INSERT/UPDATE
     * trigger makes this "impossible" - which is exactly why the job
     * re-asserts it, because a dropped trigger is silent.
     *
     * @return list<array<string, int|string>>
     */
    private function l8PartnerDiscipline(?int $fiscalYearId): array
    {
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->when($fiscalYearId !== null, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->where(function ($q) {
                $q->where(function ($missing) {
                    $missing->where('a.is_collective', true)
                        ->where(fn ($p) => $p->whereNull('l.partner_type')->orWhereNull('l.partner_id'));
                })->orWhere(function ($stray) {
                    $stray->where('a.is_collective', false)
                        ->where(fn ($p) => $p->whereNotNull('l.partner_type')->orWhereNotNull('l.partner_id'));
                });
            })
            ->selectRaw(
                'l.id, l.journal_entry_id, l.account_id, a.code, a.is_collective,'
                .' l.partner_type, l.partner_id'
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'line_id' => (int) $row->id,
                'entry_id' => (int) $row->journal_entry_id,
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->code,
                'problem' => ((bool) $row->is_collective) ? 'missing_partner' : 'stray_partner',
            ];
        }

        return $findings;
    }

    /**
     * L9 delegates entirely to the existing ReconcileAuxiliaryBalances -
     * its two SQL blocks are the spec's §8.4 queries verbatim, and a second
     * implementation here would just be a second place for the two to
     * disagree. Only out-of-balance rows are findings.
     *
     * @return list<array<string, int|string>>
     */
    private function l9AuxiliaryReconciliation(): array
    {
        $findings = [];

        foreach ($this->reconcile->handle() as $row) {
            if ($row->status !== 'out_of_balance') {
                continue;
            }

            $findings[] = [
                'account_id' => $row->account_id,
                'account_code' => $row->code,
                'gl_balance' => $row->gl_balance,
                'auxiliary_sum' => $row->auxiliary_sum,
                'difference' => $row->difference,
            ];
        }

        return $findings;
    }

    /**
     * L10 backstop: a `full` group whose member lines no longer net to zero
     * is downgraded to `partial` and reported, per §4.3's table. The
     * downgrade is this Action's one write, so it is audited.
     *
     * @return list<array<string, int|string>>
     */
    private function l10LetteringGroups(): array
    {
        $rows = DB::table('letterings as g')
            ->leftJoin('journal_entry_lines as l', 'l.lettering_id', '=', 'g.id')
            ->where('g.status', LetteringStatus::Full->value)
            ->groupBy('g.id', 'g.code', 'g.account_id')
            ->havingRaw('COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0)')
            ->selectRaw(
                'g.id, g.code, g.account_id,'
                .' COALESCE(SUM(l.debit), 0) as member_debit,'
                .' COALESCE(SUM(l.credit), 0) as member_credit'
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $letteringId = (int) $row->id;

            Lettering::query()->whereKey($letteringId)->update([
                'status' => LetteringStatus::Partial->value,
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'accounting',
                auditableType: Lettering::class,
                auditableId: $letteringId,
                before: ['status' => LetteringStatus::Full->value],
                after: ['status' => LetteringStatus::Partial->value, 'reason' => 'L10 nightly downgrade'],
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            $findings[] = [
                'lettering_id' => $letteringId,
                'code' => (string) $row->code,
                'account_id' => (int) $row->account_id,
                'member_debit' => (int) $row->member_debit,
                'member_credit' => (int) $row->member_credit,
                'action' => 'downgraded_to_partial',
            ];
        }

        return $findings;
    }

    /**
     * L11 / AN-1 backstop: for each (line, axis) present, Σ split amounts
     * must equal the line's magnitude (debit + credit - lines are
     * one-sided per L1). The analytic tables belong to a concurrent work
     * package; until they exist this section is healthy by definition.
     *
     * @return list<array<string, int|string>>
     */
    private function l11AnalyticSplits(?int $fiscalYearId): array
    {
        if (! Schema::hasTable('journal_entry_line_analytics')) {
            return [];
        }

        $rows = DB::table('journal_entry_line_analytics as s')
            ->join('journal_entry_lines as l', 'l.id', '=', 's.journal_entry_line_id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->when($fiscalYearId !== null, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->groupBy('s.journal_entry_line_id', 's.analytic_axis_id', 'l.debit', 'l.credit')
            ->havingRaw('SUM(s.amount) <> l.debit + l.credit')
            ->selectRaw(
                's.journal_entry_line_id, s.analytic_axis_id,'
                .' SUM(s.amount) as split_sum, l.debit + l.credit as line_amount'
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'line_id' => (int) $row->journal_entry_line_id,
                'axis_id' => (int) $row->analytic_axis_id,
                'split_sum' => (int) $row->split_sum,
                'line_amount' => (int) $row->line_amount,
            ];
        }

        return $findings;
    }
}
