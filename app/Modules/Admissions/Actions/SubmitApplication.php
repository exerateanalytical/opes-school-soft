<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Actions;

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Draft -> submitted, docs/specs/07-students.md 6.2.
 *
 * This is where the application number is allocated. 6.2 is explicit that the
 * wizard's "(Auto)" field is a `Sequence::peek()` PREVIEW and that the number
 * is consumed here, from the row-locked sequence table: "a previewed number
 * that is never submitted is never burned; two operators previewing
 * simultaneously see the same number and the second one's submit gets the
 * next."
 */
final class SubmitApplication
{
    /**
     * The series key carries its own scope (00-core 12). Scoping by academic
     * year rather than globally means the number restarts each session, which
     * is what the printed form's `.../2026/0078` shape implies.
     */
    public const SERIES_PREFIX = 'admission_application';

    public function handle(AdmissionApplication $application): AdmissionApplication
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $actor = $this->currentActor();

        return DB::transaction(function () use ($application, $actor): AdmissionApplication {
            /** @var AdmissionApplication|null $locked */
            $locked = AdmissionApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('The application disappeared between load and submit.');
            }

            if ($locked->status !== ApplicationStatus::Draft) {
                // Not an "already submitted, carry on" no-op: submitting twice
                // would burn a second application number for one application,
                // and gaps-permitted does not mean gaps-invited.
                throw ValidationException::withMessages([
                    'status' => __('opes.admissions_screen.errors.not_draft'),
                ]);
            }

            $this->assertComplete($locked);

            $year = $this->seriesYear($locked);
            $series = self::SERIES_PREFIX.'.'.$year;

            // Allocated INSIDE this transaction, which SequenceAllocator
            // enforces rather than documents: if the submit below rolls back,
            // the number rolls back with it.
            $next = app(SequenceAllocator::class)->allocate($series);

            $locked->application_no = sprintf('APP/%s/%04d', $year, $next);
            $locked->status = ApplicationStatus::Submitted;
            $locked->submitted_at = now();
            $locked->completed_step = WizardStep::LAST;
            $locked->current_step = WizardStep::LAST;
            $locked->updated_by = $actor->id;
            $locked->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Admissions',
                auditableType: AdmissionApplication::class,
                auditableId: (int) $locked->getKey(),
                before: ['status' => ApplicationStatus::Draft->value, 'application_no' => null],
                after: [
                    'status' => ApplicationStatus::Submitted->value,
                    'application_no' => $locked->application_no,
                ],
                actor: $actor,
            );

            return $locked;
        });
    }

    /**
     * Every step must have passed its own validation before the form counts as
     * finished. The per-step Action already refused bad data; what is checked
     * here is that no step was SKIPPED - a draft can be created at step 1 and
     * an API caller could otherwise jump straight to submit.
     *
     * @throws ValidationException
     */
    private function assertComplete(AdmissionApplication $application): void
    {
        $errors = [];

        if ($application->completed_step < WizardStep::OtherInformation->value) {
            $errors['completed_step'] = [__('opes.admissions_screen.errors.steps_incomplete')];
        }

        // Re-asserted against the columns, not merely against the high-water
        // mark: the mark says a step was passed, the columns say the data is
        // still there. They diverge if a later step ever blanks an earlier one.
        foreach (['first_name', 'last_name', 'date_of_birth', 'gender'] as $required) {
            if ($application->{$required} === null) {
                $errors[$required] = [__('opes.admissions_screen.errors.field_required')];
            }
        }

        foreach (['academic_year_id', 'class_level_id', 'admission_date'] as $required) {
            if ($application->{$required} === null) {
                $errors[$required] = [__('opes.admissions_screen.errors.field_required')];
            }
        }

        $primaries = $application->guardians()->where('is_primary', '=', true)->count();

        if ($primaries !== 1) {
            $errors['guardians'] = [__('opes.admissions_screen.errors.exactly_one_primary')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The academic year's own code where one is set, otherwise the calendar
     * year of the admission date. Never `now()`: a January submission for the
     * September session belongs to the session's series, not to January's.
     */
    private function seriesYear(AdmissionApplication $application): string
    {
        $code = DB::table('academic_years')
            ->where('id', '=', $application->academic_year_id)
            ->value('code');

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return (string) ($application->admission_date->year ?? now()->year);
    }

    /**
     * The number the next submit would take, for the wizard's read-only
     * "(Auto)" field. Peek NEVER consumes (00-core 12); this must not be used
     * to build a number that is then inserted.
     */
    public function previewNumber(AdmissionApplication $application): string
    {
        $year = $this->seriesYear($application);

        $next = app(SequenceAllocator::class)->peek(self::SERIES_PREFIX.'.'.$year);

        return sprintf('APP/%s/%04d', $year, $next);
    }

    private function currentActor(): Actor
    {
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            throw new RuntimeException('An admission cannot be submitted by an unauthenticated caller.');
        }

        return $actor;
    }
}
