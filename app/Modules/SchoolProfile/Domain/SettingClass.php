<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

/**
 * How strictly a setting is governed (docs/specs/09-ui.md 7.3).
 */
enum SettingClass: string
{
    /** Theme, page size, date format. Free edit, audited. */
    case Cosmetic = 'cosmetic';

    /** Session timeout, upload limit, thresholds. Validated, audited, immediate. */
    case Operational = 'operational';

    /**
     * Pass mark, coefficients, promotion thresholds. Validated, audited, AND
     * lockable - changing one after a period is published would retroactively
     * alter numbers already printed and handed to parents.
     */
    case EngineBehaviour = 'engine_behaviour';

    public function isLockable(): bool
    {
        return $this === self::EngineBehaviour;
    }
}
