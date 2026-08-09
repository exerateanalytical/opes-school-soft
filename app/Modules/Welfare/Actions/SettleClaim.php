<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\ClaimStatus;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Models\InsuranceClaim;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W5). Decides an open claim: settled with an
 * amount, or rejected. Terminal either way - a wrongly-decided claim is a
 * NEW claim, never a mutated one (append-only history, 00-core).
 *
 * The insurer's cash, when it arrives, is a TREASURY receipt - tracked
 * debt, plan §7 - so no ledger write happens here and none may be added
 * outside Accounting\Actions\PostFromEvent.
 */
final class SettleClaim
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $claimId,
        ClaimStatus $outcome,
        ?int $amountSettled,
        Carbon $decidedOn,
        Actor $actor,
    ): InsuranceClaim {
        Gate::authorize(InsurancePermission::MANAGE);

        return DB::transaction(function () use ($claimId, $outcome, $amountSettled, $decidedOn, $actor): InsuranceClaim {
            /** @var InsuranceClaim $claim */
            $claim = InsuranceClaim::query()->lockForUpdate()->findOrFail($claimId);

            if (! $claim->status->isOpen()) {
                throw new DomainException(
                    "Claim #{$claim->id} is already {$claim->status->value}; a decision is final."
                );
            }

            if ($outcome === ClaimStatus::Settled) {
                if ($amountSettled === null || $amountSettled <= 0) {
                    throw ValidationException::withMessages([
                        'amount_settled' => 'Settling a claim requires a positive settled amount.',
                    ]);
                }

                if ($amountSettled > $claim->amount_claimed) {
                    throw ValidationException::withMessages([
                        'amount_settled' => 'The settled amount cannot exceed the amount claimed.',
                    ]);
                }
            } elseif ($outcome === ClaimStatus::Rejected) {
                if ($amountSettled !== null) {
                    throw ValidationException::withMessages([
                        'amount_settled' => 'A rejected claim settles nothing.',
                    ]);
                }
            } else {
                throw new DomainException('A claim decision is settled or rejected.');
            }

            $before = ['status' => $claim->status->value];

            $claim->fill([
                'status' => $outcome,
                'amount_settled' => $amountSettled,
                'settled_on' => $decidedOn,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: InsuranceClaim::class,
                auditableId: (int) $claim->getKey(),
                before: $before,
                after: [
                    'status' => $outcome->value,
                    'amount_settled' => $amountSettled,
                    'settled_on' => $decidedOn->toDateString(),
                ],
                actor: $actor,
            );

            return $claim->refresh();
        });
    }
}
