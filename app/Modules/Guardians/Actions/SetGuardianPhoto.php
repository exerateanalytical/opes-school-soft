<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Set or clear a guardian's photograph (7.1 `photo_path`).
 *
 * WHY THE PHOTO IS NOT ON THE PUBLIC DISK. PortalPhotoController already
 * serves `guardians.photo_path` off the DEFAULT disk behind a policy check,
 * and a photograph of a person identified by name elsewhere in the app is not
 * something to hand out on a guessable public URL. So the bytes go through
 * StoredImage - same content-hashed naming, same never-reuse-a-path guarantee
 * - but into this module's own directory on the default disk.
 *
 * PERMISSION. `guardians.manage`, the right the module already uses for every
 * other write (7.6, and every Action in this directory). No new permission
 * string: a gate no role grants is a control that silently refuses everyone.
 *
 * DELETE-ON-REPLACE. StoredImage names a file `slug(slot)-digest`, and the
 * slot passed below is the guardian's own number, so two guardians who upload
 * identical bytes share a digest but NOT a path - each owns its file.
 *
 * (An earlier version of this note claimed they shared a path, and a test was
 * written to match. They never did. The stillReferenced() check below is kept
 * as defence in depth, so that narrowing the slot one day cannot silently
 * turn a photo replacement into someone else's photo disappearing.)
 *
 * The old file is deleted only once no other guardian row names it, and
 * StoredImage::forget() adds the two guards
 * that matter on top: never delete when the new path equals the old (a
 * re-upload of identical bytes resolves to the same file), and never delete
 * anything outside this directory, so a hand-edited column cannot turn a
 * photo change into a delete of an issued document.
 *
 * The write order is: store the new file, persist the column, THEN delete the
 * orphan. Deleting first and failing the write would lose the photograph and
 * keep the row pointing at it.
 */
final class SetGuardianPhoto
{
    public const DIRECTORY = 'guardian-photos';

    /**
     * @param  UploadedFile|null  $file  null clears the photograph
     */
    public function handle(Guardian $guardian, ?UploadedFile $file, ?Actor $actor = null): Guardian
    {
        Gate::authorize(Permission::GuardiansManage->value);

        $actor ??= $this->currentActor();

        $previous = $guardian->photo_path;

        $path = $file === null
            ? null
            : StoredImage::put(
                'guardian-'.$guardian->guardian_no,
                $file,
                self::DIRECTORY,
                $this->disk(),
            );

        DB::transaction(function () use ($guardian, $path, $previous, $actor): void {
            $guardian->photo_path = $path;
            $guardian->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: Guardian::class,
                auditableId: (int) $guardian->getKey(),
                before: ['photo_path' => $previous],
                after: ['photo_path' => $path],
                actor: $actor,
            );
        });

        if ($previous !== null && $previous !== $path && ! $this->stillReferenced($previous, $guardian)) {
            StoredImage::forget($previous, $path, self::DIRECTORY, $this->disk());
        }

        return $guardian;
    }

    private function stillReferenced(string $path, Guardian $except): bool
    {
        return Guardian::query()
            ->where('photo_path', '=', $path)
            ->whereKeyNot($except->getKey())
            ->exists();
    }

    private function disk(): string
    {
        return (string) config('filesystems.default');
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
