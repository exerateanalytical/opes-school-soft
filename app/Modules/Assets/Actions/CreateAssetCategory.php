<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.1. Creates or updates an asset category.
 *
 *  - A3: the gross account must resolve to class 2 (and not the 28/29
 *    contra families); the accumulated account to class 28. Validated here
 *    against ChartOfAccount.code prefixes via DB::table (the chart is
 *    data, and another module's Models are off-limits) - never by CHECK.
 *  - A5: once a POSTED asset exists under the category, every account FK
 *    is frozen. Corrections create a successor category and reassign, so
 *    history stays reconcilable.
 *  - Parent chains: max depth 3, cycle-checked by ancestor walk.
 *
 * A1/A2/A4 are CHECK-enforced by the schema; this Action raises the same
 * refusals with friendlier messages first.
 */
final class CreateAssetCategory
{
    /** The account FK columns A5 freezes. */
    private const ACCOUNT_COLUMNS = [
        'asset_account_id', 'accumulated_depreciation_account_id',
        'depreciation_expense_account_id', 'disposal_nbv_account_id',
        'disposal_proceeds_account_id', 'impairment_provision_account_id',
        'impairment_expense_account_id', 'revaluation_equity_account_id',
        'in_progress_account_id', 'derogatory_depreciation_account_id',
        'below_threshold_expense_account_id',
    ];

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $categoryId, array $data, Actor $actor): AssetCategory
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($categoryId, $data, $actor): AssetCategory {
            $existing = null;

            if ($categoryId !== null) {
                /** @var AssetCategory $existing */
                $existing = AssetCategory::query()->lockForUpdate()->findOrFail($categoryId);
            }

            $merged = $existing !== null
                ? [...$existing->only([
                    'code', 'name', 'name_fr', 'parent_id',
                    ...self::ACCOUNT_COLUMNS,
                    'depreciation_method', 'useful_life_months', 'declining_rate_bp',
                    'default_residual_rate_bp', 'prorata_convention',
                    'capitalisation_threshold', 'below_threshold_behaviour',
                    'requires_serial_number', 'is_archived',
                ]), ...$data]
                : $data;

            $this->validate($merged, $existing);

            if ($existing !== null) {
                $this->guardAccountFreeze($existing, $data);
                $existing->fill($data)->save();
                $category = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $category = AssetCategory::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Assets',
                auditableType: AssetCategory::class,
                auditableId: (int) $category->getKey(),
                after: [
                    'code' => $category->code,
                    'depreciation_method' => $category->depreciation_method->value,
                    'useful_life_months' => $category->useful_life_months,
                ],
                actor: $actor,
            );

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function validate(array $merged, ?AssetCategory $existing): void
    {
        if ($existing === null) {
            foreach (['code', 'name', 'name_fr'] as $field) {
                if (trim((string) ($merged[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        $field => 'An asset category requires a code and both language names.',
                    ]);
                }
            }
        }

        $methodRaw = $merged['depreciation_method'] ?? '';
        $method = $methodRaw instanceof \App\Modules\Assets\Domain\DepreciationMethod
            ? $methodRaw->value
            : (string) $methodRaw;

        // A1 - friendlier than the CHECK.
        $hasLife = ($merged['useful_life_months'] ?? null) !== null;
        if (($method === 'none') === $hasLife) {
            throw ValidationException::withMessages([
                'useful_life_months' => 'A1: useful_life_months must be set exactly when the depreciation method is not `none`.',
            ]);
        }

        // A2.
        if ($method === 'declining_balance' && ($merged['declining_rate_bp'] ?? null) === null) {
            throw ValidationException::withMessages([
                'declining_rate_bp' => 'A2: declining balance requires declining_rate_bp.',
            ]);
        }

        // A4.
        if ((int) ($merged['capitalisation_threshold'] ?? 0) > 0
            && ($merged['below_threshold_expense_account_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'below_threshold_expense_account_id' => 'A4: a positive capitalisation threshold requires the below-threshold expense account.',
            ]);
        }

        // A3 - class-prefix resolution against the chart (data, not schema).
        $this->assertAccountClass((int) $merged['asset_account_id'], 'asset_account_id', gross: true);
        $this->assertAccountClass((int) $merged['accumulated_depreciation_account_id'], 'accumulated_depreciation_account_id', gross: false);

        if (($merged['in_progress_account_id'] ?? null) !== null) {
            $this->assertAccountClass((int) $merged['in_progress_account_id'], 'in_progress_account_id', gross: true);
        }

        // Parent: depth <= 3 and no cycle (ancestor walk).
        if (($merged['parent_id'] ?? null) !== null) {
            $this->assertParentChain((int) $merged['parent_id'], $existing?->getKey());
        }
    }

    /**
     * A3: `gross` columns must be class 2 excluding the 28 (amortissements)
     * and 29 (provisions) contra families; the accumulated column must be
     * class 28.
     */
    private function assertAccountClass(int $accountId, string $field, bool $gross): void
    {
        /** @var object{code: string, is_postable: int}|null $account */
        $account = DB::table('chart_of_accounts')
            ->where('id', $accountId)
            ->first(['code', 'is_postable']);

        if ($account === null) {
            throw ValidationException::withMessages([$field => 'The account does not exist.']);
        }

        $code = $account->code;

        if ($gross) {
            $ok = str_starts_with($code, '2')
                && ! str_starts_with($code, '28')
                && ! str_starts_with($code, '29');

            if (! $ok) {
                throw new DomainException(
                    "A3: {$field} must resolve to a class-2 gross account; '{$code}' is not one."
                );
            }

            return;
        }

        if (! str_starts_with($code, '28')) {
            throw new DomainException(
                "A3: {$field} must resolve to a class-28 accumulated-depreciation account; '{$code}' is not one."
            );
        }
    }

    private function assertParentChain(int $parentId, int|string|null $selfId): void
    {
        $depth = 1; // the new/edited category itself
        $cursor = $parentId;
        $seen = [];

        while (true) {
            if ($selfId !== null && $cursor === (int) $selfId) {
                throw new DomainException('A category may not be its own ancestor (cycle).');
            }

            if (isset($seen[$cursor])) {
                throw new DomainException('Category parent chain contains a cycle.');
            }

            $seen[$cursor] = true;
            $depth++;

            if ($depth > 3) {
                throw new DomainException('Category hierarchy depth may not exceed 3.');
            }

            $next = DB::table('asset_categories')->where('id', $cursor)->value('parent_id');

            if ($next === null) {
                return;
            }

            $cursor = (int) $next;
        }
    }

    /**
     * A5: no account FK changes once a posted asset exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardAccountFreeze(AssetCategory $existing, array $data): void
    {
        $changed = [];

        foreach (self::ACCOUNT_COLUMNS as $column) {
            if (array_key_exists($column, $data)
                && ($data[$column] === null ? null : (int) $data[$column]) !== $existing->getAttribute($column)) {
                $changed[] = $column;
            }
        }

        if ($changed === []) {
            return;
        }

        $hasPostedAsset = Asset::query()
            ->where('asset_category_id', $existing->getKey())
            ->whereNotNull('journal_entry_id')
            ->exists();

        if ($hasPostedAsset) {
            throw new DomainException(
                'A5: account mappings on a category with posted assets are frozen ('
                .implode(', ', $changed)
                .'). Create a successor category and reassign instead.'
            );
        }
    }
}
