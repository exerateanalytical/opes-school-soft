<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\WithholdingBase;
use App\Modules\Tax\Domain\WithholdingType;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §6.2/§6.3 - create or reconfigure a
 * WithholdingRule VERSION, mirroring ConfigureTaxCode's append-only
 * discipline: `code`, `rate_bp`, `withholding_type` and `effective_from`
 * are immutable in place; a rate change closes the current row and creates
 * a successor. Overlap check per (code, effective window) under lock.
 *
 * Equal-top-priority rejection AT SAVE (§6.4.3): two rules with the same
 * priority, overlapping effective windows, intersecting applies_to and the
 * same supplier_condition can both survive resolution's top-priority cut,
 * which is a configuration error - rejected here, and defensively again in
 * ResolveWithholding.
 *
 * `confirm()` is the activation gate: base set (NEEDS VERIFICATION per
 * type - §12 item 13), legal_ref set, liability account wired. An
 * unconfirmed rule is never applied.
 */
final class ConfigureWithholdingRule
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{code?:string,name?:string,name_fr?:string,withholding_type?:string,rate_bp?:int,base?:string|null,applies_to?:string,minimum_base?:int,supplier_condition?:array<string,mixed>|null,priority?:int,liability_account_id?:int|null,declaration_type?:string|null,legal_ref?:string|null,effective_from?:string,effective_to?:string|null,is_active?:bool}  $attributes
     */
    public function handle(?int $ruleId, array $attributes, Actor $actor): WithholdingRule
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($ruleId, $attributes, $actor): WithholdingRule {
            if ($ruleId === null) {
                return $this->create($attributes, $actor);
            }

            return $this->update($ruleId, $attributes, $actor);
        });
    }

    /**
     * The activation gate (§6.2): an unconfirmed rule cannot be applied,
     * and a rule with an unset base / legal_ref / liability account cannot
     * be confirmed.
     */
    public function confirm(int $ruleId, Actor $actor): WithholdingRule
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($ruleId, $actor): WithholdingRule {
            /** @var WithholdingRule $rule */
            $rule = WithholdingRule::query()->lockForUpdate()->findOrFail($ruleId);

            if ($rule->base === null) {
                throw new DomainException(sprintf(
                    'Withholding rule %s cannot be activated: base (amount_ht vs amount_ttc) is unset - '
                    .'verify it with your accountant first (03-tax-procurement §6.2).',
                    $rule->code,
                ));
            }

            if ($rule->legal_ref === null || trim($rule->legal_ref) === '') {
                throw new DomainException(sprintf(
                    'Withholding rule %s cannot be activated: legal_ref is mandatory before activation (§6.2).',
                    $rule->code,
                ));
            }

            if ($rule->liability_account_id === null) {
                throw new DomainException(sprintf(
                    'Withholding rule %s cannot be activated: wire the 447 liability account first (§6.2).',
                    $rule->code,
                ));
            }

            $rule->fill([
                'is_active' => true,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: WithholdingRule::class,
                auditableId: (int) $rule->getKey(),
                after: ['confirmed' => true, 'is_active' => true],
                actor: $actor,
            );

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes, Actor $actor): WithholdingRule
    {
        foreach (['code', 'name', 'name_fr', 'withholding_type', 'rate_bp', 'applies_to', 'effective_from'] as $required) {
            if (! isset($attributes[$required])) {
                throw new DomainException(sprintf('A new withholding rule requires %s.', $required));
            }
        }

        $this->assertValid($attributes);

        // One version of a code in force per date, or the engine cannot
        // pick deterministically. Locked so two concurrent configures
        // cannot both pass.
        $siblings = WithholdingRule::query()
            ->where('code', $attributes['code'])
            ->lockForUpdate()
            ->get();

        $from = (string) $attributes['effective_from'];
        /** @var string|null $to */
        $to = $attributes['effective_to'] ?? null;

        foreach ($siblings as $sibling) {
            $siblingFrom = $sibling->effective_from->toDateString();
            $siblingTo = $sibling->effective_to?->toDateString();

            $overlaps = ($siblingTo === null || $from < $siblingTo)
                && ($to === null || $siblingFrom < $to);

            if ($overlaps) {
                throw new DomainException(sprintf(
                    'Withholding rule %s already has a version in force over part of [%s, %s); close it first.',
                    $sibling->code,
                    $from,
                    $to ?? 'open',
                ));
            }
        }

        $this->assertNoEqualPriorityClash($attributes, null);

        $rule = WithholdingRule::query()->create($attributes);

        $this->audit->handle(
            action: AuditAction::Created,
            module: 'Tax',
            auditableType: WithholdingRule::class,
            auditableId: (int) $rule->getKey(),
            after: $attributes,
            actor: $actor,
        );

        return $rule->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(int $ruleId, array $attributes, Actor $actor): WithholdingRule
    {
        /** @var WithholdingRule $rule */
        $rule = WithholdingRule::query()->lockForUpdate()->findOrFail($ruleId);

        // Append-only: these define what every historical snapshot meant.
        foreach (['code', 'rate_bp', 'withholding_type', 'effective_from'] as $frozen) {
            if (array_key_exists($frozen, $attributes)
                && $this->normalise($attributes[$frozen]) !== $this->normalise($rule->getAttribute($frozen))) {
                throw new DomainException(sprintf(
                    '%s is immutable on withholding rule %s; close this version and configure a successor.',
                    $frozen,
                    $rule->code,
                ));
            }
        }

        // Once confirmed, the base is part of what documents snapshotted.
        if ($rule->isConfirmed()
            && array_key_exists('base', $attributes)
            && $this->normalise($attributes['base']) !== $this->normalise($rule->getAttribute('base'))) {
            throw new DomainException(sprintf(
                'base is immutable on confirmed withholding rule %s; close this version and configure a successor.',
                $rule->code,
            ));
        }

        $merged = [...$rule->only($rule->getFillable()), ...$attributes];
        $this->assertValid($merged);
        $this->assertNoEqualPriorityClash($merged, (int) $rule->getKey());

        $before = $rule->only(array_keys($attributes));

        $rule->fill($attributes)->save();

        $this->audit->handle(
            action: AuditAction::Updated,
            module: 'Tax',
            auditableType: WithholdingRule::class,
            auditableId: (int) $rule->getKey(),
            before: $before,
            after: $attributes,
            actor: $actor,
        );

        return $rule->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(array $attributes): void
    {
        if (isset($attributes['withholding_type']) && is_string($attributes['withholding_type'])
            && WithholdingType::tryFrom($attributes['withholding_type']) === null) {
            throw new DomainException(sprintf('Unknown withholding type %s.', $attributes['withholding_type']));
        }

        if (isset($attributes['base']) && is_string($attributes['base'])
            && WithholdingBase::tryFrom($attributes['base']) === null) {
            throw new DomainException('base must be amount_ht or amount_ttc.');
        }

        if (isset($attributes['applies_to'])
            && ! in_array($attributes['applies_to'], ['services', 'goods', 'both', 'rent', 'commission'], true)) {
            throw new DomainException('applies_to must be services, goods, both, rent or commission.');
        }

        if (isset($attributes['rate_bp'])) {
            // Rejects negatives; stored in Rate scale so snapshots
            // round-trip exactly.
            Rate::ofBasisPoints((int) $attributes['rate_bp']);
        }

        if (isset($attributes['minimum_base']) && (int) $attributes['minimum_base'] < 0) {
            throw new DomainException('minimum_base cannot be negative.');
        }
    }

    /**
     * §6.4.3 at save time: reject a rule that could tie at top priority
     * with an existing DIFFERENT-code rule - same priority, overlapping
     * window, intersecting applies_to, identical supplier condition.
     * Different conditions are a legitimate tie-breaker (they select
     * different suppliers), so those are allowed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoEqualPriorityClash(array $attributes, ?int $exceptId): void
    {
        if (! isset($attributes['priority'], $attributes['applies_to'], $attributes['effective_from'])) {
            return;
        }

        $from = $this->normalise($attributes['effective_from']);
        $to = isset($attributes['effective_to']) ? $this->normalise($attributes['effective_to']) : '';
        $appliesTo = (string) $attributes['applies_to'];
        $condition = $this->normaliseCondition($attributes['supplier_condition'] ?? null);

        $candidates = WithholdingRule::query()
            ->where('priority', (int) $attributes['priority'])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->when(isset($attributes['code']), fn ($query) => $query->where('code', '!=', (string) $attributes['code']))
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $candidate) {
            $candidateFrom = $candidate->effective_from->toDateString();
            $candidateTo = $candidate->effective_to?->toDateString();

            $overlaps = ($candidateTo === null || $from < $candidateTo)
                && ($to === '' || $candidateFrom < $to);

            if (! $overlaps) {
                continue;
            }

            if (! $this->appliesToIntersect($appliesTo, $candidate->applies_to)) {
                continue;
            }

            if ($condition !== $this->normaliseCondition($candidate->supplier_condition)) {
                continue;
            }

            throw new DomainException(sprintf(
                'Withholding rules %s and %s would tie at equal top priority %d for the same lines - '
                .'a configuration error (03-tax-procurement §6.4); give one a higher priority.',
                (string) ($attributes['code'] ?? '?'),
                $candidate->code,
                (int) $attributes['priority'],
            ));
        }
    }

    private function appliesToIntersect(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $goodsServices = ['services', 'goods', 'both'];

        return ($a === 'both' && in_array($b, $goodsServices, true))
            || ($b === 'both' && in_array($a, $goodsServices, true));
    }

    private function normaliseCondition(mixed $condition): string
    {
        if (! is_array($condition) || $condition === []) {
            return '';
        }

        ksort($condition);

        return (string) json_encode($condition);
    }

    private function normalise(mixed $value): string
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) (is_scalar($value) ? $value : '');
    }
}
