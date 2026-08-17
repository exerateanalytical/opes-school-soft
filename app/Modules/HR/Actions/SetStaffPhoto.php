<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets or clears a staff member's photograph - the face on the staff ID card
 * (docs/specs/05-hr-payroll.md 3.3). `staff_members.photo_path` has existed
 * since the register was built and nothing ever wrote it.
 *
 * Storage rides `StoredImage`, the same content-hashed store the branding
 * images use, rather than a second mechanism: different bytes always mean a
 * different path, so replacing a photo can never leave a stale file resolving
 * under a path something else already froze.
 *
 * Content-hashing has one consequence this Action must handle that
 * DocumentProfile does not: two staff members who upload the SAME bytes get
 * the SAME path. Deleting on replace therefore asks the register whether any
 * OTHER row still points at the old path before removing the file - the
 * per-record analogue of DocumentProfile's "no longer held by any slot" rule.
 */
final class SetStaffPhoto
{
    /** The storage slot name; the content hash makes the filename unique. */
    private const SLOT = 'staff-photo';

    /**
     * @param  string  $contents  The raw image bytes.
     * @param  string  $extension  One of StoredImage::ALLOWED_EXTENSIONS.
     */
    public function set(int $staffMemberId, string $contents, string $extension, Actor $actor): string
    {
        Gate::authorize(HrPermission::MANAGE);

        // Store BEFORE the transaction: a failed write must not leave the
        // column pointing at a file that was never persisted, and an orphaned
        // file on a rolled-back save is the cheap direction of that trade.
        $path = StoredImage::putContents(self::SLOT, $contents, $extension);

        $previous = DB::transaction(function () use ($staffMemberId, $path, $actor): ?string {
            /** @var StaffMember $staff */
            $staff = StaffMember::query()->whereKey($staffMemberId)->lockForUpdate()->firstOrFail();

            $previous = $staff->photo_path;

            $staff->photo_path = $path;
            $staff->save();

            $this->audit($staff, $previous, $path, $actor);

            return $previous;
        });

        $this->forgetUnreferenced($previous, $path, $staffMemberId);

        return $path;
    }

    public function remove(int $staffMemberId, Actor $actor): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $previous = DB::transaction(function () use ($staffMemberId, $actor): ?string {
            /** @var StaffMember $staff */
            $staff = StaffMember::query()->whereKey($staffMemberId)->lockForUpdate()->firstOrFail();

            $previous = $staff->photo_path;

            if ($previous === null) {
                return null;
            }

            $staff->photo_path = null;
            $staff->save();

            $this->audit($staff, $previous, null, $actor);

            return $previous;
        });

        $this->forgetUnreferenced($previous, null, $staffMemberId);
    }

    /**
     * Delete the file a row USED to hold, unless another staff member still
     * points at it. Two people photographed from the same file share a path,
     * and deleting one person's photo must not blank the other's ID card.
     */
    private function forgetUnreferenced(?string $previous, ?string $keep, int $staffMemberId): void
    {
        if ($previous === null || $previous === '' || $previous === $keep) {
            return;
        }

        $stillReferenced = DB::table('staff_members')
            ->where('photo_path', $previous)
            ->where('id', '!=', $staffMemberId)
            ->exists();

        if ($stillReferenced) {
            return;
        }

        StoredImage::forget($previous, $keep);
    }

    private function audit(StaffMember $staff, ?string $before, ?string $after, Actor $actor): void
    {
        app(WriteAuditEntry::class)->handle(
            action: AuditAction::Updated,
            module: 'HR',
            auditableType: StaffMember::class,
            auditableId: (int) $staff->getKey(),
            before: ['photo_path' => $before],
            after: ['staff_no' => $staff->staff_no, 'photo_path' => $after],
            actor: $actor,
        );
    }
}
