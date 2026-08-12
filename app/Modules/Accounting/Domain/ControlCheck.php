<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * One row of the control matrix,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * All money is minor units. `difference` is signed and is never clamped:
 * the direction of a break tells the accountant where to look.
 */
final readonly class ControlCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $expected,
        public ?int $actual,
        public int $difference,
        public ControlStatus $status,
        public string $axis,
        public string $asOf,
        public ?string $blockingGate = null,
    ) {}

    public static function reconciledOrBroken(
        string $key,
        string $label,
        int $expected,
        int $actual,
        string $axis,
        string $asOf,
    ): self {
        $difference = $expected - $actual;

        return new self(
            key: $key,
            label: $label,
            expected: $expected,
            actual: $actual,
            difference: $difference,
            status: $difference === 0 ? ControlStatus::Reconciled : ControlStatus::Difference,
            axis: $axis,
            asOf: $asOf,
        );
    }

    public static function notConfigured(string $key, string $label, string $gate, string $axis, string $asOf): self
    {
        return new self(
            key: $key,
            label: $label,
            expected: null,
            actual: null,
            difference: 0,
            status: ControlStatus::NotConfigured,
            axis: $axis,
            asOf: $asOf,
            blockingGate: $gate,
        );
    }
}
