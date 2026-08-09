<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AcquisitionType;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.2 - registers a DRAFT asset in the register. No
 * ledger consequence yet: CapitaliseAsset posts `asset.acquired` and takes
 * the §5.3 policy snapshot. Splitting registration from capitalisation is
 * what lets a clerk key the register while the accountant controls the
 * posting (and what makes the catch-up formula's late-capitalisation case
 * an ordinary flow rather than an exception).
 */
final class RegisterAsset
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): Asset
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): Asset {
            $idempotencyKey = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var Asset|null $existing */
                $existing = Asset::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var AssetCategory $category */
            $category = AssetCategory::query()->findOrFail((int) $data['asset_category_id']);

            if ($category->is_archived) {
                throw new DomainException(
                    "Asset category '{$category->code}' is archived and accepts no new assets."
                );
            }

            if ($category->requires_serial_number
                && trim((string) ($data['serial_number'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'serial_number' => "Category '{$category->code}' requires a serial number.",
                ]);
            }

            if (trim((string) ($data['name'] ?? '')) === '') {
                throw ValidationException::withMessages(['name' => 'An asset requires a name.']);
            }

            $type = AcquisitionType::from((string) $data['acquisition_type']);
            $fairValue = isset($data['fair_value_at_donation']) ? (int) $data['fair_value_at_donation'] : null;

            if ($type === AcquisitionType::Donation && $fairValue === null) {
                throw ValidationException::withMessages([
                    'fair_value_at_donation' => 'A donated asset requires its fair value at donation (§2.2).',
                ]);
            }

            // A donated asset enters the register at fair value (§6.4).
            $cost = isset($data['acquisition_cost'])
                ? (int) $data['acquisition_cost']
                : ($type === AcquisitionType::Donation ? (int) $fairValue : 0);

            if ($cost < 0) {
                throw ValidationException::withMessages([
                    'acquisition_cost' => 'Acquisition cost cannot be negative.',
                ]);
            }

            $tag = trim((string) ($data['tag_number'] ?? ''));

            if ($tag === '') {
                // §8.6: tags come from the sequence allocator, never max()+1.
                $tag = sprintf('AST/%06d', $this->sequences->allocate('asset_tag'));
            }

            /** @var Asset $asset */
            $asset = Asset::query()->create([
                'tag_number' => $tag,
                'serial_number' => $data['serial_number'] ?? null,
                'asset_category_id' => (int) $category->getKey(),
                'parent_asset_id' => $data['parent_asset_id'] ?? null,
                'name' => (string) $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'acquisition_date' => (string) $data['acquisition_date'],
                'acquisition_cost' => $cost,
                'cost_basis' => (string) ($data['cost_basis'] ?? 'ht'),
                'non_recoverable_vat_amount' => (int) ($data['non_recoverable_vat_amount'] ?? 0),
                'residual_value' => 0,
                'acquisition_type' => $type,
                'fair_value_at_donation' => $fairValue,
                'donor_id' => $data['donor_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_invoice_id' => $data['supplier_invoice_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'custodian_staff_id' => $data['custodian_staff_id'] ?? null,
                'school_section_id' => $data['school_section_id'] ?? null,
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'academic_year_id' => (int) $data['academic_year_id'],
                'insurance_policy_ref' => $data['insurance_policy_ref'] ?? null,
                'warranty_expires_on' => $data['warranty_expires_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: Asset::class,
                auditableId: (int) $asset->getKey(),
                after: [
                    'tag_number' => $tag,
                    'category' => $category->code,
                    'acquisition_cost' => $cost,
                    'acquisition_type' => $type->value,
                ],
                actor: $actor,
            );

            return $asset;
        });
    }
}
