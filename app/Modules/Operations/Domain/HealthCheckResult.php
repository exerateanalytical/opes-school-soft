<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * One line on the health page.
 *
 * `remedy` is not optional decoration: a red light with no instruction is just
 * anxiety for a bursar who cannot read a stack trace (08-operations 7).
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $detail,
        public string $remedy = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ];
    }
}
