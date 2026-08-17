<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Actions;

use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Set or clear the applicant's photograph, docs/specs/07-students.md 6.1
 * (`admission_applications.photo_path`).
 *
 * The column has existed since the module shipped and nothing wrote to it, so
 * ConvertApplication has always passed `photoPath: $locked->photo_path` into
 * CreateStudent and always passed null. Filling the column here is therefore
 * the whole of the carry-forward: an application photographed before enrolment
 * becomes the student's photo at conversion with no cross-module write at all.
 *
 * Storage goes through StoredImage rather than a bare `$upload->store()`. The
 * content hash is what makes a REPLACE safe: new bytes get a new path, so the
 * old file can be deleted without any chance of the surviving row pointing at
 * a path whose contents have quietly changed underneath it.
 */
final class SetApplicantPhoto
{
    /**
     * Applicant photos are their own directory, not `branding/`. A per-child
     * photograph and the school crest have different retention rules - 6.5
     * pseudonymises a rejected applicant's photo away - and a shared directory
     * would put a purge job one path-prefix mistake away from deleting a
     * school's crest.
     */
    public const DIRECTORY = 'admission-photos';

    /**
     * Passing null for `$photo` REMOVES the photo: one Action, because set and
     * remove share the gate, the editability rule, the delete of the previous
     * file and the audit entry, and a second class would have to keep all four
     * in step.
     */
    public function handle(AdmissionApplication $application, ?UploadedFile $photo): AdmissionApplication
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $actor = $this->currentActor();

        /** @var array{application: AdmissionApplication, previous: string|null, path: string|null} $result */
        $result = DB::transaction(function () use ($application, $photo, $actor): array {
            /** @var AdmissionApplication|null $locked */
            $locked = AdmissionApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('The application disappeared between load and photo save.');
            }

            if (! $locked->status->isEditable()) {
                // Same rule as SaveApplicationStep: a submitted application is
                // a claim somebody has made, and swapping the face on it under
                // the same application number rewrites that claim.
                throw ValidationException::withMessages([
                    'status' => __('opes.admissions_screen.errors.not_editable'),
                ]);
            }

            $previous = $locked->photo_path;

            $path = $photo === null
                ? null
                : StoredImage::put('applicant-'.$locked->getKey(), $photo, self::DIRECTORY);

            $locked->photo_path = $path;
            $locked->updated_by = $actor->id;
            $locked->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Admissions',
                auditableType: AdmissionApplication::class,
                auditableId: (int) $locked->getKey(),
                before: ['photo_path' => $previous],
                after: ['photo_path' => $path],
                actor: $actor,
            );

            return ['application' => $locked, 'previous' => $previous, 'path' => $path];
        });

        // Deleting the file the row USED to point at, only once that row has
        // actually committed. Inside the transaction a later rollback would
        // lose the file and keep the stale path; the reverse - an orphaned
        // file after a rollback - costs disk and nothing else.
        StoredImage::forget($result['previous'], $result['path'], self::DIRECTORY);

        return $result['application'];
    }

    private function currentActor(): Actor
    {
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            throw new RuntimeException('An applicant photo cannot be set by an unauthenticated caller.');
        }

        return $actor;
    }
}
