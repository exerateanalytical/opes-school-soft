<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/**
 * The library ability names, as string constants.
 *
 * Same contract as Assets' AssetPermission: the Phase 9 wiring package (F5)
 * adds the `Identity\Domain\Permission` enum cases + role seeds + lang
 * labels for these values in ONE place; this class exists so the Library
 * Actions and screens gate on the SAME strings without parallel packages
 * editing that shared enum concurrently. Two-segment `module.action`
 * values (docs/plans/phase-09.md §5).
 */
final class LibraryPermission
{
    /** Read access to the catalogue, members and reports. */
    public const VIEW = 'library.view';

    /** Catalogue, acquisitions, membership and fine levy management. */
    public const MANAGE = 'library.manage';

    /** Issue / return / renew / reserve - the circulation desk. */
    public const CIRCULATE = 'library.circulate';

    /** Waive a fine (approver may not be the levier, §10.6). */
    public const WAIVE_FINE = 'library.waive_fine';

    private function __construct() {}
}
