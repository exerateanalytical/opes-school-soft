<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Domain\CashDeskSessionStatus;
use App\Modules\Fees\Models\CashDeskSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/04-fees.md §11.7 + docs/specs/02-accounting.md §11.5 - close the
 * till and tell the truth about it.
 *
 * The sequence is fixed and the order matters:
 *
 *  1. **Expected is COMPUTED, never supplied.** opening_float + the sum of
 *     this session's own live collections, read back from `payments` inside
 *     the lock. The cashier does not get to state what should be in the tin;
 *     that is the whole control.
 *  2. **Counted is declared by the human.**
 *  3. `variance = counted − expected`, SIGNED: negative is a shortage,
 *     positive an overage.
 *  4. A non-zero variance **requires a reason**. §11.7 makes it mandatory and
 *     the CHECK constraint on the table makes it unrepresentable otherwise;
 *     this refusal exists so the cashier reads a sentence rather than a
 *     constraint violation.
 *  5. A non-zero variance emits `cashdesk.closed_with_variance` **through
 *     PostFromEvent**. This Action writes no journal line, calls no
 *     DraftJournalEntry, touches no `journal_entry_lines`: a second posting
 *     path is the one defect this architecture refuses (02-accounting §11.1).
 *
 * A till that balances posts NOTHING. There is no entry to make - no money
 * moved, the ledger is already correct.
 *
 * If the school has not configured the shortage/overage accounts, step 5
 * raises out of PostFromEvent ("No active posting rule matches event
 * 'cashdesk.closed_with_variance'") and the ENTIRE close rolls back - the
 * session stays open. That is 02-accounting §11.5's blocking gate 6 working
 * exactly as specified: the codes 658 / 758 are flagged NEEDS VERIFICATION
 * and are not seeded, so the feature refuses to run until an accountant
 * assigns real accounts. Inventing them here would be worse than failing.
 *
 * Gated `fee.collect` for the close itself; the ledger consequence is
 * re-authorised inside the posting path.
 */
final class CloseCashDeskSession
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $sessionId,
        Money $countedCash,
        Actor $actor,
        ?string $varianceReason = null,
    ): CashDeskSession {
        Gate::authorize(Permission::FeeCollect->value);

        if ($countedCash->isNegative()) {
            throw ValidationException::withMessages([
                'counted_cash' => 'A counted till cannot be negative.',
            ]);
        }

        return DB::transaction(function () use ($sessionId, $countedCash, $actor, $varianceReason): CashDeskSession {
            /** @var CashDeskSession $session */
            $session = CashDeskSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $session->isOpen()) {
                throw ValidationException::withMessages([
                    'counted_cash' => sprintf(
                        'Session %s is already %s.',
                        $session->session_no,
                        $session->status->value,
                    ),
                ]);
            }

            $collected = $this->collections($session);
            $expected = Money::of($session->opening_float)->plus($collected);
            $variance = $countedCash->minus($expected);

            $reason = $varianceReason === null ? null : trim($varianceReason);

            if (! $variance->isZero() && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages([
                    'variance_reason' => sprintf(
                        'The till is %s by %s. A variance requires a written reason (04-fees §11.7).',
                        $variance->isNegative() ? 'short' : 'over',
                        $variance->absolute()->format(),
                    ),
                ]);
            }

            $session->forceFill([
                'status' => CashDeskSessionStatus::Closed->value,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'expected_cash' => $expected->amount(),
                'counted_cash' => $countedCash->amount(),
                'variance' => $variance->amount(),
                'variance_reason' => $variance->isZero() ? null : $reason,
            ])->save();

            if (! $variance->isZero()) {
                $entry = $this->post->handle(
                    event: PostingEvent::CashdeskClosedWithVariance->value,
                    payload: $this->payload($session, $variance),
                    date: $session->business_date->toDateString(),
                    actor: $actor,
                    reference: $session->session_no,
                    valueDate: $session->business_date->toDateString(),
                );

                $session->forceFill(['journal_entry_id' => (int) $entry->getKey()])->save();
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Fees',
                auditableType: CashDeskSession::class,
                auditableId: (int) $session->getKey(),
                before: ['status' => CashDeskSessionStatus::Open->value],
                after: [
                    'status' => CashDeskSessionStatus::Closed->value,
                    'expected_cash' => $expected->amount(),
                    'counted_cash' => $countedCash->amount(),
                    'variance' => $variance->amount(),
                    'variance_reason' => $session->variance_reason,
                    'journal_entry_id' => $session->journal_entry_id,
                ],
                actor: $actor,
            );

            return $session->refresh();
        });
    }

    /**
     * The session's own live collections: not voided, not bounced. The same
     * exclusions the §5 outstanding formula and the Cashier's own totals
     * apply, for the same reason - a voided receipt never held money.
     */
    private function collections(CashDeskSession $session): Money
    {
        $total = DB::table('payments as p')
            ->where('p.cash_desk_session_id', $session->getKey())
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->sum('p.amount');

        return Money::of((int) $total);
    }

    /**
     * The `cashdesk.closed_with_variance` payload, matching
     * PostingEvent::CashdeskClosedWithVariance's already-declared schema
     * exactly - the enum case existed with nothing firing it; this is the
     * contract it was waiting for. The schema is unchanged.
     *
     * `variance.amount` is the ABSOLUTE magnitude and `variance.is_shortage`
     * carries the direction, because 02-accounting §11.5 routes the two to
     * different accounts (65x/42x vs 75x) and a rule condition reads a bool
     * far more legibly than the sign of a signed integer.
     *
     * `variance.custodian` is the staff partner behind the cashier's user
     * account, for §11.5 Policy B ("shortage recoverable from the
     * custodian"). A cashier with no staff record yields a null tuple - the
     * rule for Policy A does not reference it at all.
     *
     * @return array<string, mixed>
     */
    private function payload(CashDeskSession $session, Money $variance): array
    {
        return [
            'variance' => [
                'amount' => $variance->absolute()->amount(),
                'is_shortage' => $variance->isNegative(),
                'reference' => $session->session_no,
                'custodian' => $this->custodian($session),
                'cash_account_id' => $session->treasury_account_id,
            ],
        ];
    }

    /**
     * @return array{type: string, id: int}|null
     */
    private function custodian(CashDeskSession $session): ?array
    {
        $staffId = DB::table('staff_members')
            ->where('portal_user_id', $session->opened_by)
            ->value('id');

        return $staffId === null ? null : ['type' => 'staff', 'id' => (int) $staffId];
    }

    /**
     * The business date a close belongs to. Kept as a named helper so the
     * Cashier screen and any future console close use one definition
     * (00-core §7.5 - Africa/Douala, never UTC).
     */
    public static function today(): string
    {
        return BusinessDate::today();
    }
}
