<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetConstructionCost;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §3 - appends a construction-cost row to an
 * in_progress asset. Posts NOTHING itself: the ledger side of the cost
 * lives with its source document (a Phase 5 supplier invoice carrying the
 * in-progress account, whose entry id is recorded here) or a manual
 * journal referenced by document_ref. CommissionAsset later transfers the
 * accumulated balance in one entry.
 */
final class RecordConstructionCost
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $options  supplier_invoice_id, journal_entry_id, document_ref, idempotency_key
     */
    public function handle(
        int $assetId,
        int $amount,
        string $incurredOn,
        string $description,
        Actor $actor,
        array $options = [],
    ): AssetConstructionCost {
        Gate::authorize(AssetPermission::MANAGE);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A construction cost must be a positive amount.',
            ]);
        }

        if (trim($description) === '') {
            throw ValidationException::withMessages([
                'description' => 'A construction cost requires a description.',
            ]);
        }

        return DB::transaction(function () use ($assetId, $amount, $incurredOn, $description, $actor, $options): AssetConstructionCost {
            $idempotencyKey = isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var AssetConstructionCost|null $existing */
                $existing = AssetConstructionCost::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Asset $asset */
            $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);

            if ($asset->status !== AssetStatus::InProgress) {
                throw new DomainException(
                    "A14: construction cost may only accumulate on an in_progress asset; '{$asset->tag_number}' is {$asset->status->value}."
                );
            }

            /** @var AssetConstructionCost $cost */
            $cost = AssetConstructionCost::query()->create([
                'asset_id' => (int) $asset->getKey(),
                'supplier_invoice_id' => $options['supplier_invoice_id'] ?? null,
                'journal_entry_id' => $options['journal_entry_id'] ?? null,
                'amount' => $amount,
                'incurred_on' => $incurredOn,
                'description' => $description,
                'document_ref' => $options['document_ref'] ?? null,
                'fiscal_year_id' => $this->yearIdCovering('fiscal_years', $incurredOn) ?? $asset->fiscal_year_id,
                'academic_year_id' => $this->yearIdCovering('academic_years', $incurredOn) ?? $asset->academic_year_id,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: AssetConstructionCost::class,
                auditableId: (int) $cost->getKey(),
                after: [
                    'asset_id' => (int) $asset->getKey(),
                    'amount' => $amount,
                    'incurred_on' => $incurredOn,
                ],
                actor: $actor,
            );

            return $cost;
        });
    }

    /** The year row covering the date, when one exists (dual calendar C3). */
    private function yearIdCovering(string $table, string $date): ?int
    {
        $id = DB::table($table)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
