<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Domain\FeeAdjustmentStatus;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §8. Approval is where the ledger consequence lands,
 * through PostFromEvent (`fee.adjustment.granted`) - the single posting path.
 *
 * Segregation of duties: `approved_by <> granted_by`, enforced HERE (the
 * useful error message lives in the Action, not only in a policy).
 *
 * The payload carries the MAGNITUDE plus the reason-resolved accounts; which
 * side each account sits on (contra-revenue 4198 for a discount vs a penalty
 * income credit for a surcharge, §8.1) is the posting rule's configuration,
 * selected by rule condition on the account ids.
 */
final class ApproveFeeAdjustment
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $adjustmentId, Actor $actor): FeeAdjustment
    {
        Gate::authorize(Permission::FeeCollect->value);

        return DB::transaction(function () use ($adjustmentId, $actor): FeeAdjustment {
            /** @var FeeAdjustment $adjustment */
            $adjustment = FeeAdjustment::query()->lockForUpdate()->findOrFail($adjustmentId);

            if ($adjustment->status !== FeeAdjustmentStatus::Pending) {
                throw new DomainException("Adjustment {$adjustment->reference_no} is {$adjustment->status->value}; only a pending adjustment can be approved.");
            }

            if ($adjustment->granted_by === $actor->id) {
                throw new DomainException(
                    "Segregation of duties: {$actor->name} granted adjustment {$adjustment->reference_no} and therefore cannot approve it - a different finance user must."
                );
            }

            $receivableAccountId = DB::table('chart_of_accounts')->where('code', '4111')->value('id');

            if ($receivableAccountId === null) {
                throw new DomainException('Account 4111 (Clients) is not in the chart of accounts; the adjustment cannot post.');
            }

            $adjustment->forceFill([
                'status' => FeeAdjustmentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $entry = $this->post->handle(
                PostingEvent::FeeAdjustmentGranted->value,
                [
                    'adjustment' => [
                        'amount' => abs($adjustment->amount),
                        'reference' => $adjustment->reference_no,
                        'partner' => ['type' => 'student', 'id' => $adjustment->student_id],
                        'receivable_account_id' => (int) $receivableAccountId,
                        'counterpart_account_id' => $adjustment->adjustment_account_id,
                    ],
                ],
                Carbon::parse($adjustment->effective_date)->toDateString(),
                $actor,
                $adjustment->reference_no,
            );

            $adjustment->forceFill(['journal_entry_id' => (int) $entry->getKey()])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'fees',
                FeeAdjustment::class,
                (int) $adjustment->getKey(),
                ['status' => 'pending'],
                ['status' => 'approved', 'approved_by' => $actor->id],
                $actor,
            );

            return $adjustment;
        });
    }
}
