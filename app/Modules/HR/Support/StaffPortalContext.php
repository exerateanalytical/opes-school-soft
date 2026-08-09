<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use Illuminate\Support\Facades\DB;

/**
 * The resolved staff portal principal for one request: which `staff_members`
 * row is behind the authenticated user (docs/plans/phase-12-13.md 12.3),
 * mirroring `Guardians\Support\PortalContext`'s shape and reasoning - a
 * staff portal request is a plain read of one row keyed by
 * `staff_members.portal_user_id`, memoised in the container so a screen that
 * asks twice in one request does not query twice.
 *
 * Read via the query builder only: `App\Modules\HR\Models\StaffMember` is
 * NOT used from outside HR's own module boundary by this class either - it
 * stays a plain DB row here so `EnsureStaffPortal` and every Livewire portal
 * screen share exactly one resolution path.
 */
final class StaffPortalContext
{
    private function __construct(
        public readonly int $staffMemberId,
        public readonly string $fullName,
    ) {}

    public static function current(): ?self
    {
        $app = app();

        if ($app->bound(self::class)) {
            return $app->make(self::class);
        }

        $context = self::resolveFromAuth();

        if ($context !== null) {
            $app->instance(self::class, $context);
        }

        return $context;
    }

    public static function resolveFromAuth(): ?self
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $status = DB::table('users')->where('id', $userId)->value('status');

        if ($status !== 'active') {
            return null;
        }

        $staff = DB::table('staff_members')
            ->where('portal_user_id', $userId)
            ->where('is_archived', false)
            ->first(['id', 'first_name', 'last_name', 'status']);

        if ($staff === null || (string) $staff->status !== 'active') {
            return null;
        }

        return new self((int) $staff->id, trim($staff->first_name.' '.$staff->last_name));
    }
}
