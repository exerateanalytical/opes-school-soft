<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * The Activities ability names, as string constants - the exact pattern
 * TransportPermission / AssetPermission set: the module's Actions and
 * screens gate on these strings, and `Identity\Domain\Permission` carries
 * the matching enum cases (ActivityView / ActivityManage) as their
 * compile-time face, added by the same change that ships this module.
 * Values follow the enum's two-segment `module.action` convention.
 */
final class ActivityPermission
{
    /** Read access to the activities list, detail tabs and registers. */
    public const VIEW = 'activity.view';

    /** Create/close activities, enrol students, sessions, attendance, consent. */
    public const MANAGE = 'activity.manage';

    private function __construct() {}
}
