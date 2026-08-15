<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Domain;

/**
 * The Curriculum ability names, as string constants.
 *
 * Same pattern as TransportPermission (Phase 10) and AssetPermission
 * (Phase 9): the module's Actions and screens gate on these strings, and
 * `Identity\Domain\Permission` carries the matching enum cases + role
 * seeds + lang labels. Values follow the enum's two-segment
 * `module.action` convention.
 */
final class CurriculumPermission
{
    /** Read access to the curriculum list and detail screens. */
    public const VIEW = 'curriculum.view';

    /** Create/revise curricula, edit units, topics and competencies, publish. */
    public const MANAGE = 'curriculum.manage';

    private function __construct() {}
}
