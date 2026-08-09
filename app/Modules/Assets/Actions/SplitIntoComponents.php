<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 06-assets-stores.md §4.6 / invariants A10, A11 - approche par
 * composants. Carves `amount` out of the parent's cost and creates child
 * assets whose costs sum EXACTLY to the reduction (`Money::allocate`,
 * largest remainder). The Action debits nothing new: the components stay
 * in the same class-2 account, so there is no ledger event - only the
 * register's shape changes. A11's conservation assertion
 * (`parent.cost + Σ children.cost` unchanged) is checked in-code and by
 * the Pest suite.
 *
 * A10: depth <= 3 and no cycle, established by an ancestor walk under
 * FOR UPDATE starting from the parent row.
 */
final class SplitIntoComponents
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<array{name: string, ratio: int, useful_life_months?: int|null, serial_number?: string|null}>  $components
     * @return list<Asset>
     */
    public function handle(int $parentAssetId, int $amount, array $components, Actor $actor): array
    {
        Gate::authorize(AssetPermission::MANAGE);

        if ($components === []) {
            throw ValidationException::withMessages([
                'components' => 'A split requires at least one component.',
            ]);
        }

        foreach ($components as $component) {
            if ($component['ratio'] <= 0) {
                throw ValidationException::withMessages([
                    'components' => 'Component ratios must be positive.',
                ]);
            }

            if (trim($component['name']) === '') {
                throw ValidationException::withMessages([
                    'components' => 'Every component needs a name.',
                ]);
            }
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'The componentised amount must be positive.',
            ]);
        }

        return DB::transaction(function () use ($parentAssetId, $amount, $components, $actor): array {
            /** @var Asset $parent */
            $parent = Asset::query()->lockForUpdate()->findOrFail($parentAssetId);

            if ($parent->status->isFrozen()) {
                throw new DomainException(
                    "A12: asset '{$parent->tag_number}' is {$parent->status->value} and refuses every mutating action."
                );
            }

            if ($parent->status === AssetStatus::Draft) {
                throw new DomainException('A draft asset has nothing to componentise; capitalise it first.');
            }

            // A10: the parent chain, walked under lock. The parent must sit
            // at depth <= 2 so its new children sit at depth <= 3.
            $depth = $this->lockedDepth($parent);

            if ($depth + 1 > 3) {
                throw new DomainException('A10: component depth may not exceed 3.');
            }

            // A8 preservation on the parent: residual must stay strictly
            // below the reduced cost.
            if ($amount >= $parent->acquisition_cost - $parent->residual_value) {
                throw new DomainException(
                    'A8: the componentised amount must leave the parent with cost above its residual value '
                    ."({$parent->acquisition_cost} cost, {$parent->residual_value} residual)."
                );
            }

            $before = $parent->acquisition_cost;

            $shares = Money::of($amount)->allocate(
                array_map(static fn (array $c): int => $c['ratio'], $components),
            );

            $children = [];

            foreach ($components as $i => $component) {
                /** @var Asset $child */
                $child = Asset::query()->create([
                    'tag_number' => sprintf('AST/%06d', $this->sequences->allocate('asset_tag')),
                    'serial_number' => $component['serial_number'] ?? null,
                    'asset_category_id' => $parent->asset_category_id,
                    'parent_asset_id' => (int) $parent->getKey(),
                    'name' => $component['name'],
                    'status' => $parent->status,
                    'acquisition_date' => $parent->acquisition_date,
                    'acquisition_cost' => $shares[$i]->amount(),
                    'cost_basis' => $parent->cost_basis,
                    'residual_value' => 0,
                    'in_service_date' => $parent->in_service_date,
                    'depreciation_start_date' => $parent->depreciation_start_date,
                    // A component carries its OWN life (§4.6); default to
                    // the parent's snapshot when not stated.
                    'useful_life_months' => $component['useful_life_months'] ?? $parent->useful_life_months,
                    'depreciation_method' => $parent->depreciation_method,
                    'prorata_convention' => $parent->prorata_convention,
                    'acquisition_type' => $parent->acquisition_type,
                    'supplier_id' => $parent->supplier_id,
                    'supplier_invoice_id' => $parent->supplier_invoice_id,
                    'location_id' => $parent->location_id,
                    'custodian_staff_id' => $parent->custodian_staff_id,
                    'school_section_id' => $parent->school_section_id,
                    'fiscal_year_id' => $parent->fiscal_year_id,
                    'academic_year_id' => $parent->academic_year_id,
                ]);

                $children[] = $child;
            }

            $parent->acquisition_cost = $before - $amount;
            $parent->save();

            // A11 - conservation, asserted, not assumed.
            $childSum = array_sum(array_map(
                static fn (Asset $c): int => $c->acquisition_cost,
                $children,
            ));

            if ($parent->acquisition_cost + $childSum !== $before) {
                throw new LogicException('A11 violated: componentisation did not conserve cost.');
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: Asset::class,
                auditableId: (int) $parent->getKey(),
                before: ['acquisition_cost' => $before],
                after: [
                    'event' => 'split_into_components',
                    'acquisition_cost' => $parent->acquisition_cost,
                    'component_ids' => array_map(static fn (Asset $c): int => (int) $c->getKey(), $children),
                    'component_total' => $childSum,
                ],
                actor: $actor,
            );

            return $children;
        });
    }

    /**
     * Depth of the asset in its component tree (root = 1), each ancestor
     * locked on the way up; refuses on a cycle.
     */
    private function lockedDepth(Asset $asset): int
    {
        $depth = 1;
        $cursor = $asset->parent_asset_id;
        $seen = [(int) $asset->getKey() => true];

        while ($cursor !== null) {
            if (isset($seen[$cursor])) {
                throw new DomainException('A10: component chain contains a cycle.');
            }

            $seen[$cursor] = true;
            $depth++;

            if ($depth > 3) {
                throw new DomainException('A10: component depth may not exceed 3.');
            }

            $next = DB::table('assets')
                ->where('id', $cursor)
                ->lockForUpdate()
                ->value('parent_asset_id');

            $cursor = $next === null ? null : (int) $next;
        }

        return $depth;
    }
}
