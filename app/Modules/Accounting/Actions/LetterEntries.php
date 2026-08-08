<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Domain\PartnerType;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Lettering;
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
 * docs/specs/02-accounting.md §10 (C10) - group a set of lines on the same
 * collective account and partner and record whether they net to zero.
 *
 * Race protection: LT-6 ("a line belongs to at most one group") is a race
 * the instant two callers try to letter overlapping line sets concurrently.
 * The lines themselves are taken `lockForUpdate()` FIRST, inside this
 * transaction, before their `lettering_id` is read - this is what actually
 * closes that race; the "FOR UPDATE on the Lettering row" language in
 * §10.2/§10.3 describes the auto-lettering Action (PaymentAllocated /
 * SupplierPaymentAllocated attaching to an EXISTING group, out of this
 * agent's scope - §10.4), where a row worth locking already exists before
 * the transaction starts. This Action always creates a brand-new row, so
 * there is nothing to lock ahead of its own creation; the line locks are
 * the mechanism that actually matters here and are strictly stronger.
 *
 * L10 (Sigma-debit = Sigma-credit for a `full` group) is proved once, in
 * PHP, using Money - never SQL arithmetic (§10.4's explicit instruction for
 * the sibling auto-lettering Action applies equally here).
 */
final class LetterEntries
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly SequenceAllocator $sequences,
    ) {}

    /**
     * @param  list<int>  $lineIds
     */
    public function handle(array $lineIds, Actor $actor): Lettering
    {
        // §10.4 gates unlettering on `accounting.unletter`; §11 gates
        // reversal/lettering on `ledger.post` per this agent's brief
        // (app/Modules/Identity/Domain/Permission.php does not yet declare
        // a dedicated `accounting.letter`/`accounting.unletter` case).
        Gate::authorize(Permission::LedgerPost->value);

        $lineIds = array_values(array_unique($lineIds));

        if ($lineIds === []) {
            throw new DomainException('At least one line is required to letter.');
        }

        return DB::transaction(function () use ($lineIds, $actor): Lettering {
            /** @var \Illuminate\Support\Collection<int, JournalEntryLine> $lines */
            $lines = JournalEntryLine::query()
                ->whereIn('id', $lineIds)
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== count($lineIds)) {
                throw new DomainException('One or more lines do not exist.');
            }

            /** @var JournalEntryLine $first */
            $first = $lines->first();

            $accountId = (int) $first->account_id;
            $partnerType = $first->partner_type;
            $partnerId = $first->partner_id;

            if ($partnerType === null || $partnerId === null) {
                // Structurally impossible on a lettrable (therefore
                // collective) account once L8's trigger is in place, but
                // checked explicitly rather than assumed.
                throw new DomainException('L8/LT-1: lettering requires lines that carry a partner.');
            }

            foreach ($lines as $line) {
                if ((int) $line->account_id !== $accountId
                    || $line->partner_type !== $partnerType
                    || (int) $line->partner_id !== (int) $partnerId
                ) {
                    throw new DomainException('LT-1: every line in a lettering group must share the same account and partner.');
                }

                if ($line->lettering_id !== null) {
                    throw new DomainException('LT-6: a line already belongs to a lettering group.');
                }
            }

            $account = ChartOfAccount::query()->findOrFail($accountId);

            if (! $account->is_lettrable) {
                throw new DomainException(sprintf('Account %s is not lettrable.', $account->code));
            }

            // LT-5: only lines on a posted or reversed entry may be
            // lettered - never a draft.
            $entryIds = $lines->pluck('journal_entry_id')->unique()->values()->all();
            $hasBadStatus = JournalEntry::query()
                ->whereIn('id', $entryIds)
                ->whereNotIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->exists();

            if ($hasBadStatus) {
                throw new DomainException('LT-5: only lines on a posted or reversed entry may be lettered.');
            }

            $totalDebit = Money::zero();
            $totalCredit = Money::zero();

            foreach ($lines as $line) {
                $totalDebit = $totalDebit->plus(Money::of((int) $line->debit));
                $totalCredit = $totalCredit->plus(Money::of((int) $line->credit));
            }

            // LT-2/LT-4: full requires Sigma-debit = Sigma-credit AND at
            // least two lines - a single line can never balance, because
            // ck_jel_one_side forbids a 0/0 line, so its lone debit or
            // credit is always nonzero.
            $status = ($totalDebit->equals($totalCredit) && $lines->count() >= 2)
                ? LetteringStatus::Full
                : LetteringStatus::Partial;

            $code = $this->nextCode($accountId, $partnerType, (int) $partnerId);

            $lettering = Lettering::query()->create([
                'account_id' => $accountId,
                'partner_type' => $partnerType,
                'partner_id' => $partnerId,
                'code' => $code,
                'status' => $status,
                'total_debit' => $totalDebit->amount(),
                'total_credit' => $totalCredit->amount(),
                'lettered_by' => $actor->id,
                'lettered_at' => now(),
                'is_auto' => false,
            ]);

            JournalEntryLine::query()
                ->whereIn('id', $lineIds)
                ->update(['lettering_id' => $lettering->getKey()]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: Lettering::class,
                auditableId: (int) $lettering->getKey(),
                after: [
                    'code' => $code,
                    'status' => $status->value,
                    'line_ids' => $lineIds,
                    'total_debit' => $totalDebit->amount(),
                    'total_credit' => $totalCredit->amount(),
                ],
                actor: $actor,
            );

            return $lettering->refresh();
        });
    }

    /**
     * `AA`, `AB`, ... per (account, partner), per §10.2. Scoped through
     * SequenceAllocator so two concurrent LetterEntries calls against the
     * same (account, partner) never mint the same code.
     */
    private function nextCode(int $accountId, PartnerType $partnerType, int $partnerId): string
    {
        $series = sprintf('lettering.%d.%s.%d', $accountId, $partnerType->value, $partnerId);
        $n = $this->sequences->allocate($series);

        $first = intdiv($n - 1, 26);
        $second = ($n - 1) % 26;

        if ($first > 25) {
            throw new DomainException('Lettering code space exhausted for this account/partner.');
        }

        return chr(65 + $first).chr(65 + $second);
    }
}
