<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The calculation-order dependency graph (docs/specs/05-hr-payroll.md 7):
 * Kahn's algorithm over `depends_on`, the higher-or-equal-order dependency
 * rule, and the ORDER-200 BASES BARRIER - no earning at or above it, no
 * statutory component below it. The barrier ORDER is passed in, never
 * hard-coded here (4.3): it is the `calculation_order` convention the
 * component seed carries, and the Actions layer supplies it.
 *
 * Execution order is ascending `calculation_order`, ties broken by `code`
 * ascending - deterministic, never insertion order (7.2 rule 1).
 */
final readonly class ComponentGraph
{
    /**
     * @param  list<array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}>  $components
     */
    public function __construct(
        private array $components,
        private int $basesBarrierOrder,
    ) {
    }

    /**
     * Validates the whole graph; throws ComponentGraphInvalid naming the
     * offenders. Returns the components in execution order.
     *
     * @return list<array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}>
     */
    public function validated(): array
    {
        $byCode = [];

        foreach ($this->components as $component) {
            $byCode[$component['code']] = $component;
        }

        foreach ($this->components as $component) {
            $this->assertBarrier($component);

            foreach ($component['depends_on'] as $dependency) {
                if (! array_key_exists($dependency, $byCode)) {
                    throw ComponentGraphInvalid::unknownDependency($component['code'], $dependency);
                }

                // 7.2 rule 2: a dependency must run strictly BEFORE its
                // dependant - equal order included, because ties resolve by
                // code, which is not an ordering anyone configured.
                if ($byCode[$dependency]['order'] >= $component['order']) {
                    throw ComponentGraphInvalid::forwardDependency($component['code'], $dependency);
                }
            }
        }

        $this->assertAcyclic($byCode);

        return $this->sorted();
    }

    /**
     * Ascending calculation_order, ties by code (7.2 rule 1).
     *
     * @return list<array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}>
     */
    public function sorted(): array
    {
        $sorted = $this->components;

        usort(
            $sorted,
            static fn (array $a, array $b): int => ($a['order'] <=> $b['order']) ?: strcmp($a['code'], $b['code']),
        );

        return $sorted;
    }

    /**
     * @param  array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}  $component
     */
    private function assertBarrier(array $component): void
    {
        if ($component['type'] === ComponentType::Earning
            && $component['order'] >= $this->basesBarrierOrder) {
            throw ComponentGraphInvalid::earningAboveBarrier($component['code']);
        }

        if ($component['calculation'] === ComponentCalculation::Statutory
            && $component['order'] < $this->basesBarrierOrder) {
            throw ComponentGraphInvalid::statutoryBelowBarrier($component['code']);
        }
    }

    /**
     * Kahn's algorithm by repeated removal: a component whose remaining
     * dependency set is empty is "ready"; if nothing is ready and unready
     * components remain, those remaining ARE the cycle members.
     *
     * @param  array<string, array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}>  $byCode
     */
    private function assertAcyclic(array $byCode): void
    {
        /** @var array<string, array<string, true>> $pending code => unresolved dependency set */
        $pending = [];

        foreach ($byCode as $code => $component) {
            $unresolved = [];

            foreach ($component['depends_on'] as $dependency) {
                $unresolved[$dependency] = true;
            }

            $pending[$code] = $unresolved;
        }

        while ($pending !== []) {
            $ready = [];

            foreach ($pending as $code => $unresolved) {
                if ($unresolved === []) {
                    $ready[] = $code;
                }
            }

            if ($ready === []) {
                $members = array_map(strval(...), array_keys($pending));
                sort($members);

                throw ComponentGraphInvalid::cycle($members);
            }

            foreach ($ready as $code) {
                unset($pending[$code]);

                foreach ($pending as $other => $unresolved) {
                    unset($pending[$other][$code]);
                }
            }
        }
    }
}
