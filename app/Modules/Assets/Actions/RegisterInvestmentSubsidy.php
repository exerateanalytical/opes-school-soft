<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\SubsidyStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\InvestmentSubsidy;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §6.3 - registers a subvention d'investissement and
 * links it to the funded asset. POSTS NOTHING: the balance-sheet credit to
 * 14 is the capitalisation entry's job (the donation `asset.acquired` rule
 * credits the class-14 account instead of 481, §6.4), and the quote-part
 * releases belong to the depreciation run (PostDepreciationRun).
 *
 * release_income_account_id may stay NULL (V5 - 845 unverified): releases
 * are then skipped-with-exception until an accountant configures it.
 */
final class RegisterInvestmentSubsidy
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{reference: string, donor_partner_id: int, subsidy_account_id: int, release_income_account_id?: int|null, granted_amount: int, granted_on: string, agreement_ref?: string|null, conditions?: string|null, fiscal_year_id: int, academic_year_id: int, asset_id?: int|null, idempotency_key?: string|null}  $data
     */
    public function handle(array $data, Actor $actor): InvestmentSubsidy
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): InvestmentSubsidy {
            $idempotencyKey = $data['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                $existing = InvestmentSubsidy::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($data['granted_amount'] <= 0) {
                throw ValidationException::withMessages([
                    'granted_amount' => 'A subsidy must carry a positive granted amount.',
                ]);
            }

            $subsidy = InvestmentSubsidy::query()->create([
                'reference' => $data['reference'],
                'donor_partner_id' => $data['donor_partner_id'],
                'subsidy_account_id' => $data['subsidy_account_id'],
                'release_income_account_id' => $data['release_income_account_id'] ?? null,
                'granted_amount' => $data['granted_amount'],
                'granted_on' => $data['granted_on'],
                'agreement_ref' => $data['agreement_ref'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'status' => SubsidyStatus::Active->value,
                'idempotency_key' => $idempotencyKey,
            ]);

            $assetId = $data['asset_id'] ?? null;

            if ($assetId !== null) {
                /** @var Asset|null $asset */
                $asset = Asset::query()->lockForUpdate()->find($assetId);

                if ($asset === null) {
                    throw new DomainException("Asset {$assetId} does not exist.");
                }

                if ($asset->status->isFrozen()) {
                    throw new DomainException(
                        "Asset '{$asset->tag_number}' is {$asset->status->value}; a subsidy cannot fund it (A12)."
                    );
                }

                if ($asset->investment_subsidy_id !== null) {
                    throw new DomainException(
                        "Asset '{$asset->tag_number}' is already funded by subsidy #{$asset->investment_subsidy_id}."
                    );
                }

                if ($asset->acquisition_cost > 0 && $data['granted_amount'] > $asset->acquisition_cost) {
                    throw ValidationException::withMessages([
                        'granted_amount' => 'A subsidy cannot exceed the funded asset\'s acquisition cost.',
                    ]);
                }

                $asset->forceFill([
                    'investment_subsidy_id' => (int) $subsidy->getKey(),
                ])->save();
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: InvestmentSubsidy::class,
                auditableId: (int) $subsidy->getKey(),
                after: [
                    'reference' => $subsidy->reference,
                    'granted_amount' => $subsidy->granted_amount,
                    'asset_id' => $assetId,
                ],
                actor: $actor,
            );

            return $subsidy;
        });
    }
}
