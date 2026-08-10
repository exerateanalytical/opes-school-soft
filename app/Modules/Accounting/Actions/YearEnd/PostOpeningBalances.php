<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §18.2, step 11 of §17.2 - the à-nouveaux.
 * Event `year_end.opening_balances`, journal **AN**, dated
 * `next_fiscal_year.starts_on`.
 *
 * Three properties of this entry are the whole point, and each is enforced
 * rather than hoped for:
 *
 *  1. **Classes 1-5 only.** 6, 7 and 8 are zero after §18.1 and are not
 *     carried. If any of them still has a balance, this Action REFUSES and
 *     names the accounts - carrying a class 7 balance into the new exercice
 *     would restate last year's revenue as this year's.
 *  2. **Partner detail is preserved.** Every collective-account balance is
 *     carried as one line per (partner, due_date), never as a collective
 *     lump. §18.2: "carrying a lump 4111 balance destroys the auxiliary
 *     ledger on 1 January of every year and makes L9 unprovable". Keeping
 *     the due date is what lets aging survive the boundary.
 *  3. **It comes first.** The AN is posted before the new year takes any
 *     user entry, so no operational entry precedes it in `piece_no` order.
 *     If the new year already carries a posted entry, this refuses.
 *
 * Idempotency is the `fiscal_years.opening_entry_id` back-reference, which
 * 2026_08_10_360002 made UNIQUE: one AN per exercice, checked under
 * `FOR UPDATE` and enforced by the index even against a second writer.
 *
 * The entry is generated, never edited (§18.2): a correction is a reversal
 * plus a fresh entry, like anything else in this ledger.
 */
final class PostOpeningBalances
{
    public const PERMISSION = Permission::LedgerPost->value;

    public function __construct(
        private readonly PostFromEvent $poster,
        private readonly YearEndBalances $balances,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $fiscalYearId, Actor $actor): JournalEntry
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($fiscalYearId, $actor): JournalEntry {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fiscalYear->closing_entry_id === null) {
                throw new DomainException(sprintf(
                    'Fiscal year %s has not been closed (§18.1). The à-nouveaux carries classes 1-5 only, and classes 6/7/8 are not yet zero.',
                    $fiscalYear->code,
                ));
            }

            $next = $this->nextYear($fiscalYear);

            if ($next->opening_entry_id !== null) {
                $pieceNo = DB::table('journal_entries')->where('id', $next->opening_entry_id)->value('piece_no');

                throw new DomainException(sprintf(
                    'Fiscal year %s already has an à-nouveaux entry (%s). Posting a second one would double every opening balance.',
                    $next->code,
                    is_string($pieceNo) ? $pieceNo : (string) $next->opening_entry_id,
                ));
            }

            $this->assertNextYearUntouched($next);
            $this->assertResultAccountsZeroed($fiscalYearId);

            [$lines, $partnerLines, $total] = $this->buildLines($fiscalYearId);

            $payload = [
                'closing' => [
                    'amount' => $total,
                    'reference' => 'AN-'.$next->code,
                    'result_account_id' => 0,
                    'counterpart_account_id' => 0,
                    'lines' => $lines,
                    'partner_lines' => $partnerLines,
                ],
            ];

            $entry = $this->poster->handle(
                event: PostingEvent::YearEndOpeningBalances->value,
                payload: $payload,
                date: $next->starts_on->toDateString(),
                actor: $actor,
                reference: 'AN-'.$next->code,
            );

            $next->forceFill(['opening_entry_id' => $entry->getKey()])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: FiscalYear::class,
                auditableId: (int) $next->getKey(),
                after: [
                    'opening_entry_id' => (int) $entry->getKey(),
                    'carried_from' => $fiscalYear->code,
                    'line_count' => count($lines) + count($partnerLines),
                    'total' => $total,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    private function nextYear(FiscalYear $fiscalYear): FiscalYear
    {
        /** @var FiscalYear|null $next */
        $next = FiscalYear::query()
            ->whereDate('starts_on', $fiscalYear->ends_on->copy()->addDay()->toDateString())
            ->lockForUpdate()
            ->first();

        if ($next === null) {
            throw new DomainException(sprintf(
                'No fiscal year starts on %s; create the next exercice before carrying balances into it.',
                $fiscalYear->ends_on->copy()->addDay()->toDateString(),
            ));
        }

        $period = DB::table('accounting_periods')
            ->where('fiscal_year_id', $next->getKey())
            ->whereDate('starts_on', '<=', $next->starts_on->toDateString())
            ->whereDate('ends_on', '>=', $next->starts_on->toDateString())
            ->first();

        if ($period === null) {
            throw new DomainException(sprintf(
                'No accounting period of %s covers %s; the à-nouveaux cannot be dated into it.',
                $next->code,
                $next->starts_on->toDateString(),
            ));
        }

        if ($period->status !== AccountingPeriodStatus::Open->value) {
            throw new DomainException(sprintf(
                'The first period of %s is %s; the à-nouveaux must post into an open period.',
                $next->code,
                (string) $period->status,
            ));
        }

        return $next;
    }

    /** §18.2: the AN precedes every user entry of the new exercice. */
    private function assertNextYearUntouched(FiscalYear $next): void
    {
        $existing = DB::table('journal_entries')
            ->where('fiscal_year_id', $next->getKey())
            ->whereIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->count();

        if ($existing > 0) {
            throw new DomainException(sprintf(
                '%s already carries %d posted entr%s. §18.2 requires the à-nouveaux to be posted BEFORE the new year is opened for operational posting, so that no user entry precedes it in piece_no order.',
                $next->code,
                $existing,
                $existing === 1 ? 'y' : 'ies',
            ));
        }
    }

    private function assertResultAccountsZeroed(int $fiscalYearId): void
    {
        $residual = $this->balances->perAccount($fiscalYearId, [6, 7, 8]);
        $residualPartners = $this->balances->perPartner($fiscalYearId, [6, 7, 8]);

        if ($residual === [] && $residualPartners === []) {
            return;
        }

        $codes = array_map(static fn (array $row): string => $row['code'], $residual);

        foreach ($residualPartners as $row) {
            $codes[] = $row['code'];
        }

        throw new DomainException(sprintf(
            'Classes 6/7/8 are not zero after the closing entry (%s still carry a balance). §18.2 carries classes 1-5 ONLY; fix the closing entry before carrying forward.',
            implode(', ', array_values(array_unique($codes))),
        ));
    }

    /**
     * @return array{
     *     list<array{amount: int, target_account_id: int, label: string}>,
     *     list<array{amount: int, target_account_id: int, label: string, partner: array{type: string, id: int}, due_date: string|null}>,
     *     int
     * }
     */
    private function buildLines(int $fiscalYearId): array
    {
        $classes = [1, 2, 3, 4, 5];

        $orphans = $this->balances->orphanedCollectiveLines($fiscalYearId, $classes);

        if ($orphans !== []) {
            $codes = implode(', ', array_map(static fn (array $row): string => $row['code'], $orphans));

            throw new DomainException(
                "These collective accounts carry partner-less lines (L8): {$codes}. Carrying them forward would put a lump balance on a collective account and make L9 unprovable for the new exercice; fix them first."
            );
        }

        $lines = [];
        $partnerLines = [];
        $signedTotal = Money::zero();
        $debitTotal = Money::zero();

        foreach ($this->balances->perAccount($fiscalYearId, $classes) as $row) {
            // Carried AS IS: a debit balance opens as a debit.
            $lines[] = [
                'amount' => $row['balance'],
                'target_account_id' => $row['account_id'],
                'label' => 'A-nouveau '.$row['code'].' - '.$row['name'],
            ];

            $signedTotal = $signedTotal->plus(Money::of($row['balance']));

            if ($row['balance'] > 0) {
                $debitTotal = $debitTotal->plus(Money::of($row['balance']));
            }
        }

        foreach ($this->balances->perPartner($fiscalYearId, $classes) as $row) {
            $partnerLines[] = [
                'amount' => $row['balance'],
                'target_account_id' => $row['account_id'],
                'label' => 'A-nouveau '.$row['code'],
                'partner' => ['type' => $row['partner_type'], 'id' => $row['partner_id']],
                'due_date' => $row['due_date'],
            ];

            $signedTotal = $signedTotal->plus(Money::of($row['balance']));

            if ($row['balance'] > 0) {
                $debitTotal = $debitTotal->plus(Money::of($row['balance']));
            }
        }

        if ($lines === [] && $partnerLines === []) {
            throw new DomainException('No class 1-5 account carries a balance; there is nothing to carry forward.');
        }

        // No balancing line exists, and none should: an à-nouveaux that does
        // not balance on its own means the closed exercice did not balance,
        // which is a defect to be fixed - never plugged with a residual on
        // some arbitrary account.
        if (! $signedTotal->isZero()) {
            throw new DomainException(sprintf(
                'The classes 1-5 balances do not net to zero (residual %s). This means the closed exercice does not balance once classes 6/7/8 are removed - most often an unappropriated result or a class 9 account that is not self-balancing. The à-nouveaux refuses to plug it.',
                $signedTotal->format(),
            ));
        }

        return [$lines, $partnerLines, $debitTotal->amount()];
    }
}
