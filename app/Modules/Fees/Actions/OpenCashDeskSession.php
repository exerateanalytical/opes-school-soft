<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\CashDeskSessionStatus;
use App\Modules\Fees\Models\CashDeskSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/04-fees.md §11.7 / §17.2 - open the till.
 *
 * The cashier declares an opening float and names the cash box. From this
 * moment every cash collection they take is attached to this row, which is
 * the only thing that makes the close-out arithmetic possible.
 *
 * Three refusals, all of them the point of the feature:
 *
 *  - the box must be a real class-5 **57x** *Caisse* account (a session on a
 *    bank account is meaningless - you do not count a bank);
 *  - it must be postable and not archived, because money is going to land in
 *    it (the same test RecordPayment applies to `treasury_account_id`);
 *  - one open session per (user, business_date), per §11.7. The DATABASE
 *    holds that line via the `open_session_key` generated column; the check
 *    below exists to give a human a sentence instead of a duplicate-key
 *    stack trace, not to be the guarantee.
 *
 * Gated `fee.collect`: opening a till is part of collecting, and the same
 * permission already guards the act it exists to enable.
 */
final class OpenCashDeskSession
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $treasuryAccountId,
        Money $openingFloat,
        Actor $actor,
        ?string $businessDate = null,
        ?string $notes = null,
    ): CashDeskSession {
        Gate::authorize(Permission::FeeCollect->value);

        if ($actor->id === null) {
            throw ValidationException::withMessages([
                'opened_by' => 'A cash-desk session belongs to a named cashier; the system actor cannot open one.',
            ]);
        }

        if ($openingFloat->isNegative()) {
            throw ValidationException::withMessages([
                'opening_float' => 'An opening float cannot be negative.',
            ]);
        }

        $businessDate ??= BusinessDate::today();

        $this->assertCashBox($treasuryAccountId);

        $userId = $actor->id;

        return DB::transaction(function () use ($treasuryAccountId, $openingFloat, $actor, $businessDate, $notes, $userId): CashDeskSession {
            $open = CashDeskSession::query()
                ->where('opened_by', $userId)
                ->where('status', CashDeskSessionStatus::Open->value)
                ->first();

            if ($open !== null) {
                throw ValidationException::withMessages([
                    'opening_float' => sprintf(
                        'Session %s is still open (opened %s). Close it before opening another (04-fees §11.7).',
                        $open->session_no,
                        $open->opened_at->format('Y-m-d H:i'),
                    ),
                ]);
            }

            // §14 / 00-core §12: gaps permitted, allocated under the row lock
            // inside this transaction - never max()+1.
            $sequence = $this->sequences->allocate('cash_desk_session_no');
            $sessionNo = sprintf('CDS/%s/%06d', substr($businessDate, 0, 4), $sequence);

            /** @var CashDeskSession $session */
            $session = CashDeskSession::query()->create([
                'session_no' => $sessionNo,
                'treasury_account_id' => $treasuryAccountId,
                'business_date' => $businessDate,
                'opened_by' => $userId,
                'opened_at' => now(),
                'opening_float' => $openingFloat->amount(),
                'status' => CashDeskSessionStatus::Open->value,
                'notes' => $notes,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: CashDeskSession::class,
                auditableId: (int) $session->getKey(),
                after: [
                    'session_no' => $sessionNo,
                    'treasury_account_id' => $treasuryAccountId,
                    'business_date' => $businessDate,
                    'opening_float' => $openingFloat->amount(),
                ],
                actor: $actor,
            );

            return $session;
        });
    }

    /**
     * Cross-module read through the query builder, never Accounting\Models
     * (00-core §6.2).
     */
    private function assertCashBox(int $treasuryAccountId): void
    {
        /** @var object{code: string, account_class: int|string, is_postable: int|string, is_archived: int|string}|null $account */
        $account = DB::table('chart_of_accounts')
            ->where('id', $treasuryAccountId)
            ->first(['code', 'account_class', 'is_postable', 'is_archived']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'treasury_account_id' => 'The selected cash box does not exist.',
            ]);
        }

        if ((int) $account->account_class !== 5 || ! str_starts_with($account->code, '57')) {
            throw ValidationException::withMessages([
                'treasury_account_id' => sprintf(
                    'Account %s is not a cash box; a cash-desk session runs on a 57… Caisse account (02-accounting §2).',
                    $account->code,
                ),
            ]);
        }

        if ((bool) $account->is_archived || ! (bool) $account->is_postable) {
            throw ValidationException::withMessages([
                'treasury_account_id' => sprintf(
                    'Account %s is archived or not postable; money cannot land in it.',
                    $account->code,
                ),
            ]);
        }
    }
}
