<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDirectory;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Http\JsonResponse;

/**
 * The guardian's own bootstrap and children list
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4, rows 1-3).
 *
 * Thin, like every adapter here (00-core §6.1): PortalContext is already
 * resolved and bound by EnsureGuardianApi, capability answers come from
 * GuardianPortalPolicy, assembly comes from ChildDirectory. This class chooses
 * a shape and nothing else.
 */
final class MeController
{
    public function __construct(
        private readonly ChildDirectory $directory,
        private readonly GuardianPortalPolicy $policy,
    ) {
    }

    /** `GET /v1/me` - who the app is signed in as. */
    public function show(): JsonResponse
    {
        $context = $this->context();
        $guardian = $context->guardian;

        return response()->json([
            'data' => [
                'guardian' => [
                    'id' => (int) $guardian->getKey(),
                    'display_name' => $guardian->fullName(),
                    'first_name' => $guardian->first_name,
                    'last_name' => $guardian->last_name,
                    'phone' => $guardian->phone,
                    'email' => $guardian->email,
                    'language' => $guardian->language->value,
                ],
                // Row 26 and row 29 are granted on "any valid link" without
                // naming a child, so they belong here rather than per child.
                'capabilities_global' => $this->globalCapabilities(),
                'as_of' => $context->asOf,
            ],
        ]);
    }

    /** `GET /v1/me/children` - row 1 per child, plus the render contract. */
    public function children(): JsonResponse
    {
        return response()->json([
            'data' => $this->directory->children($this->context()),
        ]);
    }

    /**
     * `GET /v1/me/dashboard` - the home screen in one round trip.
     *
     * Aggregate rather than a new source of truth: it returns the children
     * list and the counts the dashboard shows, each already gated. A tile the
     * principal may not see is absent, not zero - zero would tell them a
     * number exists and is empty, which is itself a disclosure.
     */
    public function dashboard(): JsonResponse
    {
        $context = $this->context();
        $children = $this->directory->children($context);

        return response()->json([
            'data' => [
                'children' => $children,
                'children_count' => count($children),
                'capabilities_global' => $this->globalCapabilities(),
                'as_of' => $context->asOf,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function globalCapabilities(): array
    {
        $global = [
            GuardianCapability::R16ViewOwnPayments,
            GuardianCapability::R26ViewTimetableAndAnnouncements,
            GuardianCapability::R29EditOwnContactDetails,
        ];

        $granted = [];

        foreach ($global as $capability) {
            if ($this->policy->allowsForAnyChild($capability)) {
                $granted[] = $capability->value;
            }
        }

        return $granted;
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            // EnsureGuardianApi has already refused this case; belt and braces.
            abort(403);
        }

        return $context;
    }
}
