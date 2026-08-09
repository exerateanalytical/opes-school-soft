<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

use App\Modules\Operations\Domain\Licensing\LicenceState;
use DomainException;

/**
 * Thrown by Operations\Actions\AssertEntitlement when one of the four gated
 * annual/termly operations is attempted in an `enforced` or `revoked` state
 * (docs/specs/08-operations.md §4.4). A DomainException so the existing
 * Action error surfaces render it like every other refusal; carries the
 * state and operation so screens can be specific without string-parsing.
 */
final class EntitlementBlocked extends DomainException
{
    public function __construct(
        public readonly LicenceState $state,
        public readonly string $operation,
    ) {
        parent::__construct((string) __('licence.blocked.'.$state->value, [
            'operation' => (string) __('licence.operation.'.$operation),
        ]));
    }
}
