<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DomainException;

/**
 * The component set failed DAG validation (docs/specs/05-hr-payroll.md
 * 7.2): a dependency cycle, a forward dependency onto an equal-or-higher
 * calculation order, or a component on the wrong side of the order-200
 * bases barrier. Each factory names the offending members - a graph error
 * is a configuration bug someone must fix by name.
 */
final class ComponentGraphInvalid extends DomainException
{
    /**
     * @param  list<string>  $members
     */
    public static function cycle(array $members): self
    {
        return new self(sprintf(
            'Payroll component dependencies form a cycle involving: %s (05-hr-payroll 7.2 rule 2).',
            implode(', ', $members),
        ));
    }

    public static function forwardDependency(string $code, string $dependsOn): self
    {
        return new self(sprintf(
            "Component %s depends on %s, which does not run before it - a component may not depend on one with a higher or equal calculation_order (05-hr-payroll 7.2 rule 2).",
            $code,
            $dependsOn,
        ));
    }

    public static function unknownDependency(string $code, string $dependsOn): self
    {
        return new self(sprintf(
            'Component %s depends on %s, which is not in the enabled component set.',
            $code,
            $dependsOn,
        ));
    }

    public static function earningAboveBarrier(string $code): self
    {
        return new self(sprintf(
            'Component %s is an earning ordered at or above the bases barrier - no earning may be added after the bases are materialised (05-hr-payroll 7.2 rule 3).',
            $code,
        ));
    }

    public static function statutoryBelowBarrier(string $code): self
    {
        return new self(sprintf(
            'Component %s is statutory but ordered below the bases barrier - no statutory component may be evaluated before the bases are materialised (05-hr-payroll 7.2 rule 3).',
            $code,
        ));
    }
}
