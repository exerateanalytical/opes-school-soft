<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ResultAppropriation;
use App\Modules\Accounting\Models\ResultAppropriationLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §18.3, step 10 of §17.2 - posts the approved
 * appropriation: **13** against **11** / reserves / distributions, journal
 * **OD**, event `year_end.appropriation`, through `PostFromEvent`.
 *
 * AP-1 is re-asserted HERE, under `FOR UPDATE`, against compte 13's balance
 * as it stands at this instant - not against the number stored when the
 * draft was keyed. Between the two, someone may have reversed the closing
 * entry or posted a late adjustment; the appropriation must empty 13 as it
 * IS, or refuse.
 *
 * Idempotency: an already-approved appropriation carrying a journal entry
 * refuses by naming that entry. `status = approved` and `journal_entry_id`
 * are written in the same transaction as the post.
 */
final class PostResultAppropriation
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

            if ($fiscalYear->dsf_filed_at !== null) {
                throw new DomainException(sprintf(
                    'The DSF for %s was filed on %s; nothing further may be posted into it.',
                    $fiscalYear->code,
                    $fiscalYear->dsf_filed_at->toDateString(),
                ));
            }

            /** @var ResultAppropriation|null $appropriation */
            $appropriation = ResultAppropriation::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->lockForUpdate()
                ->first();

            if ($appropriation === null) {
                throw new DomainException(sprintf(
                    'No appropriation has been recorded for %s. Key the resolution first (RecordResultAppropriation); the product will not invent an allocation of the result.',
                    $fiscalYear->code,
                ));
            }

            if ($appropriation->journal_entry_id !== null) {
                $pieceNo = DB::table('journal_entries')->where('id', $appropriation->journal_entry_id)->value('piece_no');

                throw new DomainException(sprintf(
                    'The appropriation of %s is already posted (%s).',
                    $fiscalYear->code,
                    is_string($pieceNo) ? $pieceNo : (string) $appropriation->journal_entry_id,
                ));
            }

            /** @var ChartOfAccount $resultAccount */
            $resultAccount = ChartOfAccount::query()
                ->where('code', PostYearEndClosingEntry::RESULT_ACCOUNT_CODE)
                ->firstOrFail();

            $balance = $this->balances->accountBalance($fiscalYearId, (int) $resultAccount->getKey());
            $resultAmount = -$balance;

            if ($resultAmount === 0) {
                throw new DomainException(
                    'Compte 13 is already empty; there is no result left to appropriate.'
                );
            }

            /** @var list<ResultAppropriationLine> $lines */
            $lines = $appropriation->lines()->get()->all();

            if ($lines === []) {
                throw new DomainException('The appropriation has no allocation lines.');
            }

            $allocated = Money::zero();

            foreach ($lines as $line) {
                $allocated = $allocated->plus(Money::of($line->amount));
            }

            if ($allocated->amount() !== $resultAmount) {
                throw new DomainException(sprintf(
                    'AP-1 violated at posting time: the allocation totals %s but compte 13 currently holds %s. Re-key the appropriation against the ledger as it stands.',
                    $allocated->format(),
                    Money::of($resultAmount)->format(),
                ));
            }

            // 13 first (its side is the result, debit for a profit), then
            // the allocations, each the negation - so the payload's signed
            // amounts sum to zero by construction.
            $payloadLines = [[
                'amount' => $resultAmount,
                'target_account_id' => (int) $resultAccount->getKey(),
                'label' => 'Affectation du resultat '.$fiscalYear->code,
            ]];

            foreach ($lines as $line) {
                $payloadLines[] = [
                    'amount' => -$line->amount,
                    'target_account_id' => $line->account_id,
                    'label' => $line->label,
                ];
            }

            $payload = [
                'closing' => [
                    'amount' => Money::of($resultAmount)->absolute()->amount(),
                    'reference' => $appropriation->resolution_reference,
                    'result_account_id' => (int) $resultAccount->getKey(),
                    'counterpart_account_id' => $lines[0]->account_id,
                    'lines' => $payloadLines,
                    'partner_lines' => [],
                ],
            ];

            $entry = $this->poster->handle(
                event: PostingEvent::YearEndAppropriation->value,
                payload: $payload,
                date: $fiscalYear->ends_on->toDateString(),
                actor: $actor,
                reference: $appropriation->resolution_reference,
            );

            $appropriation->forceFill([
                'status' => ResultAppropriation::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'journal_entry_id' => $entry->getKey(),
            ])->save();

            $fiscalYear->forceFill([
                'result_appropriation_id' => $appropriation->getKey(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: ResultAppropriation::class,
                auditableId: (int) $appropriation->getKey(),
                after: [
                    'status' => ResultAppropriation::STATUS_APPROVED,
                    'journal_entry_id' => (int) $entry->getKey(),
                    'result_amount' => $resultAmount,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }
}
