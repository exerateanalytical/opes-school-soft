<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\AcquisitionSource;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\BookAcquisition;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.8 - an acquisition BATCH (purchase, donation or
 * transfer) plus its physical copies.
 *
 * Policy (`SchoolProfile.library_capitalisation_policy`):
 *  - `expensed` (default): the batch posts NOTHING here. A purchased
 *    batch's expense is the Phase 5 supplier invoice's posting;
 *    a donated batch is a memorandum with a donor acknowledgement letter.
 *    Per-copy `acquisition_cost` is retained for insurance and
 *    replacement-cost purposes only.
 *  - `capitalised`: HARD-GATED - the SYSCOHADA account for a fonds
 *    documentaire is NEEDS VERIFICATION (V17), so the Action refuses,
 *    naming the item, rather than guessing an account (00-core §16).
 */
final class RecordBookAcquisition
{
    public function __construct(
        private readonly AddBookCopies $addCopies,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     reference: string,
     *     acquired_on: string,
     *     source: string,
     *     supplier_id?: int|null,
     *     supplier_invoice_id?: int|null,
     *     notes?: string|null,
     *     lines: list<array{book_id: int, shelf_location_id: int, count: int, unit_cost?: int, condition?: string}>,
     *     idempotency_key?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): BookAcquisition
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var BookAcquisition|null $existing */
            $existing = BookAcquisition::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($data['lines'] === []) {
            throw new DomainException('A book acquisition needs at least one line.');
        }

        if ($this->capitalisationPolicy() === 'capitalised') {
            throw new DomainException(
                'The `capitalised` library policy is not selectable: the SYSCOHADA account for a '
                .'fonds documentaire is NEEDS VERIFICATION (06-assets-stores V17). The accountant '
                .'must confirm it before acquisitions can capitalise; the default `expensed` '
                .'policy records the batch without posting.'
            );
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): BookAcquisition {
            $source = AcquisitionSource::from($data['source']);

            $totalCost = 0;
            $copyCount = 0;

            foreach ($data['lines'] as $line) {
                if ($line['count'] < 1) {
                    throw new DomainException('Each acquisition line needs a positive copy count.');
                }

                $totalCost += ($line['unit_cost'] ?? 0) * $line['count'];
                $copyCount += $line['count'];
            }

            /** @var BookAcquisition $acquisition */
            $acquisition = BookAcquisition::query()->create([
                'reference' => $data['reference'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_invoice_id' => $data['supplier_invoice_id'] ?? null,
                'acquired_on' => $data['acquired_on'],
                'source' => $source,
                'total_cost' => $totalCost,
                'copy_count' => $copyCount,
                'journal_entry_id' => null, // expensed policy: posts nothing here
                'asset_id' => null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($data['lines'] as $line) {
                $this->addCopies->handle([
                    'book_id' => $line['book_id'],
                    'shelf_location_id' => $line['shelf_location_id'],
                    'count' => $line['count'],
                    'condition' => $line['condition'] ?? 'new',
                    'acquisition_id' => (int) $acquisition->getKey(),
                    'acquired_on' => $data['acquired_on'],
                    'unit_cost' => $line['unit_cost'] ?? 0,
                ], $actor);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Library',
                auditableType: BookAcquisition::class,
                auditableId: (int) $acquisition->getKey(),
                after: [
                    'reference' => $acquisition->reference,
                    'source' => $source->value,
                    'total_cost' => $totalCost,
                    'copy_count' => $copyCount,
                ],
                actor: $actor,
            );

            return $acquisition->refresh();
        });
    }

    /**
     * SchoolProfile is data, not schema: the policy lives in the settings
     * table (F5 seeds the key). Absent → the spec's stated default,
     * `expensed` (§10.8) - the one value that posts nothing and therefore
     * cannot be wrong.
     */
    private function capitalisationPolicy(): string
    {
        /** @var string|null $raw */
        $raw = DB::table('settings')
            ->whereIn('key', ['library.capitalisation_policy', 'library_capitalisation_policy'])
            ->value('value');

        if ($raw === null) {
            return 'expensed';
        }

        $decoded = json_decode($raw, true);

        return is_string($decoded) ? $decoded : 'expensed';
    }
}
