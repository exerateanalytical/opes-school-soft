<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

use App\Modules\Operations\Domain\Licensing\EntitlementDecision;
use App\Modules\Operations\Domain\Licensing\LicenceState;
use App\Modules\Operations\Models\Licence;
use Illuminate\Support\Carbon;

/**
 * The complete answer of one OFFLINE licence status check
 * (docs/specs/08-operations.md §4.3-§4.4). Produced only by LicenceStatus;
 * consumed by AssertEntitlement (state -> decision) and the Licence panel
 * (everything else).
 */
final readonly class LicenceEvaluation
{
    public function __construct(
        public LicenceState $state,
        /** The cached row, when one exists - even when it failed verification. */
        public ?Licence $licence,
        /** True only when signature + product + fingerprint + expiry parse all passed. */
        public bool $trusted,
        /** lang/{en,fr}/licence.php key naming WHY the cached row is untrusted, else null. */
        public ?string $failureKey,
        /** The verified expiry (from the SIGNED payload, not the cache columns). */
        public ?Carbon $expiresOn,
        /** Last day of the unlicensed trial window, when the trial clock has started. */
        public ?Carbon $trialEndsOn,
        public int $studentCount,
    ) {
    }

    public function decision(): EntitlementDecision
    {
        return $this->state->decision();
    }

    /**
     * @return array<string, mixed>  The verified payload, or [] when untrusted/absent.
     */
    public function payload(): array
    {
        if (! $this->trusted || $this->licence === null) {
            return [];
        }

        /** @var array<string, mixed> */
        return $this->licence->payload;
    }
}
