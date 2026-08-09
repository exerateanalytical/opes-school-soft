<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Policies;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Domain\GuardianScopeMatrix;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The ONE policy of the guardian portal. Every portal screen and every portal
 * download asks this class, and this class asks GuardianScopeMatrix - nothing
 * else decides (07-students 7.5: "every policy calls it; nothing else may
 * make this decision").
 *
 * Deliberately NOT a Laravel model policy: the subject of a portal decision
 * is a (link, capability) pair, not a model instance, and the Student model
 * belongs to another module this one must not import. Components resolve it
 * from the container and call allows()/authorize() with the child's id.
 *
 * What this class adds to the matrix is exactly the resolution 7.5 assigns to
 * the caller and no more:
 *
 *   - portal principal -> Guardian (PortalContext, the same resolution the
 *     middleware performed);
 *   - (guardian, student) -> the currently-valid StudentGuardian link, or
 *     null, which is row 32: a child they are not linked to yields deny for
 *     every capability;
 *   - link -> GuardianAuthorizationFlags -> the matrix.
 *
 * Narrowing conjuncts that live in other modules' data - publication state
 * (row 8's "checked first"), promotion `applied` (row 10), case visibility
 * (row 20) - are ANDed by the query the screen runs. They can only narrow a
 * grant given here, never widen a deny.
 */
final class GuardianPortalPolicy
{
    /**
     * May the current portal principal exercise $capability for the child
     * $studentId? False when there is no portal principal at all.
     */
    public function allows(GuardianCapability $capability, int $studentId): bool
    {
        $context = PortalContext::current();

        if ($context === null) {
            return false;
        }

        $link = $context->linkFor($studentId);

        if ($link === null) {
            // Row 32: anything concerning a child they are not linked to.
            return false;
        }

        return GuardianScopeMatrix::allows($link->authorizationFlags($context->asOf), $capability);
    }

    /**
     * The child-less variant for the capabilities 7.5 grants on "any valid
     * link" without naming a child: row 16 (payments made by this guardian,
     * any child), row 26 (timetable/announcements) and row 29 (their own
     * contact details). Also correct for the portal dashboard shell itself.
     *
     * Still routed through the matrix, per link: if every link the guardian
     * holds has expired, this is false - which is 7.5's historic-access rule.
     * The row-16 nuance (their own payment RECORDS outlive the links) is a
     * query concern on `payments.payer_guardian_id`, not a portal-entry
     * grant; see the matrix header.
     */
    public function allowsForAnyChild(GuardianCapability $capability): bool
    {
        $context = PortalContext::current();

        if ($context === null) {
            return false;
        }

        foreach ($context->validLinks() as $link) {
            if (GuardianScopeMatrix::allows($link->authorizationFlags($context->asOf), $capability)) {
                return true;
            }
        }

        return false;
    }

    /** allows(), throwing instead of returning false. */
    public function authorize(GuardianCapability $capability, int $studentId): void
    {
        if (! $this->allows($capability, $studentId)) {
            throw new AuthorizationException('This action is not authorized for this child.');
        }
    }

    /** allowsForAnyChild(), throwing instead of returning false. */
    public function authorizeForAnyChild(GuardianCapability $capability): void
    {
        if (! $this->allowsForAnyChild($capability)) {
            throw new AuthorizationException('This action is not authorized.');
        }
    }
}
