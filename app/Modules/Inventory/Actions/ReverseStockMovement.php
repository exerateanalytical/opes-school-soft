<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md I11 - movements are NEVER updated or
 * deleted (engine triggers enforce it); a correction is a COMPENSATING
 * movement carrying `reversal_of_movement_id`, mirroring the ledger's C9
 * reversal rule:
 *
 *  - a movement is reversed at most once (DB UNIQUE is the backstop);
 *  - a reversal is never itself reversed;
 *  - the accounting leg reverses through Accounting's own
 *    ReverseJournalEntry - the ONE reversal path - so the stock ledger and
 *    the GL stay tied;
 *  - a movement whose journal entry covers OTHER movements too (a
 *    multi-line issue header) is refused: reverse the document, not one
 *    line of its entry.
 *
 * The balance invariants police the rest: reversing a receipt after later
 * consumption fails I6/I8/I9 loudly instead of leaving a lying bin.
 */
final class ReverseStockMovement
{
    use MovesStock;

    public function __construct(
        private readonly ReverseJournalEntry $reverse,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $movementId, string $reason, Actor $actor): StockMovement
    {
        Gate::authorize(InventoryPermission::POST);

        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('A stock-movement reversal needs a stated reason (at least 5 characters).');
        }

        return DB::transaction(function () use ($movementId, $reason, $actor): StockMovement {
            /** @var StockMovement|null $original */
            $original = StockMovement::query()->lockForUpdate()->find($movementId);

            if ($original === null) {
                throw new DomainException("Stock movement {$movementId} does not exist.");
            }

            if ($original->reversal_of_movement_id !== null) {
                throw new DomainException(
                    'A reversal movement is never itself reversed (I11); correct forward instead.'
                );
            }

            $alreadyReversed = StockMovement::query()
                ->where('reversal_of_movement_id', $original->getKey())
                ->exists();

            if ($alreadyReversed) {
                throw new DomainException(
                    "Stock movement {$movementId} is already reversed; a movement reverses at most once (I11)."
                );
            }

            $this->lockLocation($original->store_location_id);
            $balance = $this->lockBalance($original->item_id, $original->store_location_id);

            // The ledger leg first: through Accounting's one reversal door.
            $reversalEntryId = null;
            $deferredReason = $original->posting_deferred_reason;

            if ($original->journal_entry_id !== null) {
                $sharedEntry = StockMovement::query()
                    ->where('journal_entry_id', $original->journal_entry_id)
                    ->where('id', '!=', $original->getKey())
                    ->exists();

                if ($sharedEntry) {
                    throw new DomainException(
                        "Stock movement {$movementId} shares its journal entry with other movements (one entry per issue header, §7.8); reverse the whole document instead."
                    );
                }

                $reversalEntry = $this->reverse->handle($original->journal_entry_id, $reason, $actor);
                $reversalEntryId = (int) $reversalEntry->getKey();
                $deferredReason = null;
            }

            $reversal = $this->applyMovement(
                $balance,
                $original->movement_type->reversalType(),
                $this->negate($original->quantity),
                -$original->total_cost,
                $original->moved_on->toDateString(),
                $original->fiscal_year_id,
                $original->academic_year_id,
                $actor,
                [
                    'journal_entry_id' => $reversalEntryId,
                    'posting_deferred_reason' => $deferredReason,
                    'reversal_of_movement_id' => (int) $original->getKey(),
                    'reference_type' => $original->reference_type,
                    'reference_id' => $original->reference_id,
                    'document_ref' => 'REV: '.$reason,
                ],
            );

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockMovement::class,
                (int) $reversal->getKey(),
                null,
                [
                    'reversal_of_movement_id' => (int) $original->getKey(),
                    'reason' => $reason,
                    'journal_entry_id' => $reversalEntryId,
                ],
                $actor,
            );

            return $reversal;
        });
    }

    private function negate(string $quantity): string
    {
        return \App\Modules\Inventory\Domain\WeightedAverageCost::compare($quantity, '0') >= 0
            ? '-'.ltrim($quantity, '+')
            : ltrim($quantity, '-');
    }
}
