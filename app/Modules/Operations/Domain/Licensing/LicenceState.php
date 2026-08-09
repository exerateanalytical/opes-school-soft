<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Licensing;

/**
 * The graduated entitlement states of docs/specs/08-operations.md §4.4.
 * Evaluated OFFLINE from the cached licence row (or the trial clock when no
 * licence exists) - no state transition ever involves a network call.
 */
enum LicenceState: string
{
    case Valid = 'valid';

    // No licence row, within 30 days or 25 students - fully permissive
    // (phase-07 plan risk note: this must be the test-fixture default).
    case Trial = 'trial';

    // <= 30 days to expiry: dismissible banner, nothing blocked.
    case Expiring = 'expiring';

    // Expired < 30 days ago: persistent banner, nothing blocked.
    case Grace = 'grace';

    // Expired past grace: the four annual/termly operations are blocked;
    // daily operations and every export never are.
    case Enforced = 'enforced';

    case Revoked = 'revoked';

    public function label(string $locale = 'en'): string
    {
        // lang/en/licence.php + lang/fr/licence.php are owned by the
        // licensing workstream (phase-07 plan §3); until they land the raw
        // key renders, which the schema tests do not assert against.
        return __('licence.state.'.$this->value, [], $locale);
    }

    /**
     * States that surface a banner (§4.4 table). Expiring is dismissible;
     * grace/enforced/revoked are persistent.
     */
    public function showsBanner(): bool
    {
        return $this !== self::Valid && $this !== self::Trial;
    }

    public function decision(): EntitlementDecision
    {
        return EntitlementDecision::forState($this);
    }
}
