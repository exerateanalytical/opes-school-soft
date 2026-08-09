<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 04-fees.md §2.5. Renames, re-dating and line replacement.
 *
 *  - `draft` edits freely; the version stays put.
 *  - `active` (published) edits BUMP THE VERSION - invoices stamp the
 *    version they were billed under, so the stamp stays meaningful.
 *  - `archived` is history and refuses every edit.
 *
 * Scope columns (year, section, level, stream, scopes, effective_from) are
 * deliberately NOT editable here: they are the identity of the structure in
 * the UNIQUE key and in the resolution rule. A differently-scoped price is
 * a new structure, not a mutation of this one.
 *
 * Status transitions: draft→active (publish), active→archived. Publishing
 * an empty structure is refused - it would resolve and bill nothing.
 */
final class UpdateFeeStructure
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{fee_item_id: int, amount: int, term_id?: int, service_period_start?: string|null, service_period_end?: string|null, is_optional?: bool, display_order?: int}>|null  $lines
     */
    public function handle(
        int $feeStructureId,
        ?string $name = null,
        ?string $effectiveTo = null,
        ?array $lines = null,
        ?FeeStructureStatus $status = null,
        ?Actor $actor = null,
    ): FeeStructure {
        Gate::authorize(Permission::FeeConfigure->value);

        return DB::transaction(function () use ($feeStructureId, $name, $effectiveTo, $lines, $status, $actor): FeeStructure {
            /** @var FeeStructure $structure */
            $structure = FeeStructure::query()->lockForUpdate()->findOrFail($feeStructureId);

            if ($structure->status === FeeStructureStatus::Archived) {
                throw new DomainException('An archived fee structure is history and cannot be edited.');
            }

            $before = [
                'name' => $structure->name,
                'status' => $structure->status->value,
                'version' => $structure->version,
            ];

            $contentChanged = false;

            if ($name !== null && $name !== $structure->name) {
                $structure->name = $name;
                $contentChanged = true;
            }

            if ($effectiveTo !== null) {
                if (! $structure->effective_from->lessThan($effectiveTo)) {
                    throw new DomainException('effective_to is exclusive and must be after effective_from.');
                }

                $structure->effective_to = \Illuminate\Support\Carbon::parse($effectiveTo);
                $contentChanged = true;
            }

            if ($lines !== null) {
                $this->replaceLines($structure, $lines);
                $contentChanged = true;
            }

            if ($status !== null && $status !== $structure->status) {
                $this->transition($structure, $status);
            }

            // §2.5: version is bumped on every published change; invoices
            // stamp the version. Draft churn is not versioned.
            if ($contentChanged && $structure->status === FeeStructureStatus::Active) {
                $structure->version++;
            }

            $structure->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Fees',
                auditableType: FeeStructure::class,
                auditableId: (int) $structure->getKey(),
                before: $before,
                after: [
                    'name' => $structure->name,
                    'status' => $structure->status->value,
                    'version' => $structure->version,
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $structure;
        });
    }

    private function transition(FeeStructure $structure, FeeStructureStatus $to): void
    {
        $allowed = match ($structure->status) {
            FeeStructureStatus::Draft => $to === FeeStructureStatus::Active,
            FeeStructureStatus::Active => $to === FeeStructureStatus::Archived,
            FeeStructureStatus::Archived => false,
        };

        if (! $allowed) {
            throw new DomainException(sprintf(
                'A fee structure cannot move from %s to %s.',
                $structure->status->value,
                $to->value,
            ));
        }

        if ($to === FeeStructureStatus::Active && ! $structure->lines()->exists()) {
            throw new DomainException('A fee structure with no lines cannot be published - it would bill nothing.');
        }

        $structure->status = $to;
    }

    /**
     * @param  list<array{fee_item_id: int, amount: int, term_id?: int, service_period_start?: string|null, service_period_end?: string|null, is_optional?: bool, display_order?: int}>  $lines
     */
    private function replaceLines(FeeStructure $structure, array $lines): void
    {
        // Lines are config children (CASCADE, §2.5): replacement is safe
        // because invoices SNAPSHOT everything they bill (§3.2) - deleting a
        // config line never touches financial history.
        $structure->lines()->delete();

        foreach ($lines as $index => $line) {
            if ($line['amount'] < 0) {
                throw new DomainException('A fee structure line amount cannot be negative.');
            }

            $item = FeeItem::query()->find($line['fee_item_id']);

            if ($item === null) {
                throw new DomainException('A fee structure line references a fee item that does not exist.');
            }

            if ($item->is_archived) {
                throw new DomainException(sprintf('Fee item %s is archived and cannot be billed.', $item->code));
            }

            $termId = $line['term_id'] ?? FeeStructureLine::ANNUAL;

            if ($termId !== FeeStructureLine::ANNUAL
                && ! DB::table('assessment_periods')->where('id', $termId)->exists()) {
                throw new DomainException('A fee structure line references a term that does not exist.');
            }

            $structure->lines()->create([
                'fee_item_id' => $line['fee_item_id'],
                'amount' => $line['amount'],
                'term_id' => $termId,
                'service_period_start' => $line['service_period_start'] ?? null,
                'service_period_end' => $line['service_period_end'] ?? null,
                'is_optional' => $line['is_optional'] ?? false,
                'display_order' => $line['display_order'] ?? $index,
            ]);
        }
    }
}
