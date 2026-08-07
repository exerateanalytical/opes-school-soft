<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Actions;

use App\Modules\Admissions\Domain\ApplicantRelationship;
use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Admissions\Models\AdmissionApplicationGuardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * One step of the admission wizard, validated and persisted.
 *
 * docs/specs/07-students.md 6.2: "the application row is created with
 * status='draft' on first Next. Each step is a partial save; a power cut loses
 * at most one step." That sentence is the entire contract of this class - the
 * row is written per step, not per wizard, and the step rules live here rather
 * than in the Livewire component so that an importer or an API caller gets the
 * same gate the operator does.
 */
final class SaveApplicationStep
{
    /**
     * @param  array<string, mixed>  $data  the step's fields; for step 3 it
     *                                      carries a `guardians` list.
     *
     * @throws ValidationException
     */
    public function handle(
        WizardStep $step,
        array $data,
        ?AdmissionApplication $application = null,
    ): AdmissionApplication {
        Gate::authorize(Permission::AdmissionsManage->value);

        if ($application !== null && ! $application->status->isEditable()) {
            // A submitted application is a claim someone has made; editing it
            // silently under the same id would rewrite that claim. 6.2 gives
            // the wizard drafts only.
            throw ValidationException::withMessages([
                'status' => __('opes.admissions_screen.errors.not_editable'),
            ]);
        }

        $validated = Validator::make($data, self::rulesFor($step), self::messages())->validate();

        $actor = $this->currentActor();

        return DB::transaction(function () use ($step, $validated, $application, $actor): AdmissionApplication {
            $isNew = $application === null;

            $attributes = $this->columnsFor($step, $validated);
            $attributes['current_step'] = $step->value;

            if ($isNew) {
                $attributes['status'] = ApplicationStatus::Draft;
                $attributes['completed_step'] = $step->value;
                $attributes['created_by'] = $actor->id;
                $attributes['updated_by'] = $actor->id;

                $application = AdmissionApplication::query()->create($attributes);
            } else {
                // The high-water mark only ever rises. Walking Back to step 2
                // and saving it again must not un-complete steps 3 and 4 -
                // 11.4 requires Back never to lose entered data, and that
                // includes not losing the operator's progress.
                $attributes['completed_step'] = max($application->completed_step, $step->value);
                $attributes['updated_by'] = $actor->id;

                $application->fill($attributes)->save();
            }

            if ($step === WizardStep::ParentGuardian) {
                $this->replaceGuardians($application, $validated);
            }

            app(WriteAuditEntry::class)->handle(
                action: $isNew ? AuditAction::Created : AuditAction::Updated,
                module: 'Admissions',
                auditableType: AdmissionApplication::class,
                auditableId: (int) $application->getKey(),
                after: [
                    // Field NAMES, never field VALUES. The identity block is
                    // encrypted at rest precisely so it is not casually
                    // readable; copying it into an audit row - which is
                    // deliberately append-only and exportable - would undo
                    // that in the one table nobody can redact afterwards.
                    'step' => $step->value,
                    'step_key' => $step->key(),
                    'fields' => array_keys($attributes),
                    'status' => $application->status->value,
                ],
                actor: $actor,
            );

            return $application;
        });
    }

    /**
     * The per-step validation rules. Public and static so the wizard can show
     * the same required-field markers the Action will enforce, and so a test
     * can assert the two never drift.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(WizardStep $step): array
    {
        return match ($step) {
            WizardStep::BasicInformation => [
                'first_name' => ['required', 'string', 'max:80'],
                'middle_name' => ['nullable', 'string', 'max:80'],
                'last_name' => ['required', 'string', 'max:80'],
                // `before:today` and not merely `date`: a date of birth in the
                // future is the single most common typo on this form (a
                // mis-keyed year) and it silently poisons every age-derived
                // figure downstream, because 3.1 stores no age to contradict.
                'date_of_birth' => ['required', 'date', 'before:today'],
                'gender' => ['required', Rule::in(['male', 'female'])],
                'nationality' => ['required', 'string', 'size:2'],
                'place_of_birth' => ['nullable', 'string', 'max:120'],
                'state_of_origin' => ['nullable', 'string', 'max:80'],
                'religion' => ['nullable', 'string', 'max:60'],
                'blood_group' => ['nullable', 'string', 'max:5'],
                'genotype' => ['nullable', 'string', 'max:5'],
            ],
            WizardStep::AcademicDetails => [
                'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
                'admission_term_id' => ['nullable', 'integer', 'exists:assessment_periods,id'],
                'school_section_id' => ['nullable', 'integer', 'exists:school_sections,id'],
                // The applied-for LEVEL. There is deliberately no class group
                // field: 6.3 chooses the group at conversion, under capacity.
                'class_level_id' => ['required', 'integer', 'exists:class_levels,id'],
                'stream_id' => ['nullable', 'integer', 'exists:streams,id'],
                'category' => ['nullable', 'string', 'max:40'],
                'admission_date' => ['required', 'date'],
                'proposed_roll_number' => ['nullable', 'integer', 'min:1', 'max:500'],
            ],
            WizardStep::ParentGuardian => [
                'guardians' => ['required', 'array', 'min:1', 'max:5'],
                'guardians.*.title' => ['nullable', 'string', 'max:16'],
                'guardians.*.first_name' => ['required', 'string', 'max:80'],
                'guardians.*.last_name' => ['required', 'string', 'max:80'],
                // NOT NULL on `guardians` (7.1). Asked for here rather than at
                // conversion, where a missing value would abort a transaction
                // that has already created a student.
                'guardians.*.gender' => ['required', Rule::in(['male', 'female'])],
                // Optional, but it is tier 3 of the 7.7 duplicate-match key
                // (last name + first name + DOB), and that tier returns
                // nothing at all without it.
                'guardians.*.date_of_birth' => ['nullable', 'date', 'before:today'],
                'guardians.*.relationship' => ['required', Rule::enum(ApplicantRelationship::class)],
                // 7.2 makes this mandatory when, and only when, the
                // relationship is `other`. That is a per-row conditional the
                // flat rule table cannot see, so the shape is checked here and
                // the condition in assertGuardianInvariants().
                'guardians.*.relationship_other' => ['nullable', 'string', 'max:60'],
                'guardians.*.is_primary' => ['boolean'],
                'guardians.*.id_type' => [
                    'nullable',
                    Rule::in(['national_id', 'passport', 'residence_permit', 'drivers_licence', 'other']),
                ],
                'guardians.*.id_number' => ['nullable', 'string', 'max:40'],
                'guardians.*.occupation' => ['nullable', 'string', 'max:120'],
                'guardians.*.employer' => ['nullable', 'string', 'max:120'],
                'guardians.*.phone' => ['required', 'string', 'max:24'],
                'guardians.*.alternative_phone' => ['nullable', 'string', 'max:24'],
                'guardians.*.email' => ['nullable', 'email', 'max:160'],
                'guardians.*.address_line' => ['nullable', 'string', 'max:255'],
                'guardians.*.city' => ['nullable', 'string', 'max:80'],
                'guardians.*.region' => ['nullable', 'string', 'max:80'],
                'guardians.*.language' => ['nullable', Rule::in(['en', 'fr'])],
                'guardians.*.has_custody' => ['boolean'],
                'guardians.*.receives_reports' => ['boolean'],
                'guardians.*.receives_invoices' => ['boolean'],
                'guardians.*.is_emergency_contact' => ['boolean'],
                'guardians.*.is_authorised_for_pickup' => ['boolean'],
                'guardians.*.is_fee_payer' => ['boolean'],
            ],
            WizardStep::OtherInformation => [
                'previous_school_name' => ['nullable', 'string', 'max:160'],
                'last_class_completed' => ['nullable', 'string', 'max:80'],
                'year_completed' => ['nullable', 'integer', 'min:1900', 'max:2200'],
                'reason_for_leaving' => ['nullable', 'string', 'max:255'],
                'special_information' => ['nullable', 'string', 'max:2000'],
            ],
            // Nothing of its own is required at review: 6.2 says cross-step
            // validation here WARNS rather than blocks, because Cameroonian
            // schools legitimately admit over-age students and a hard block
            // would teach operators to falsify the date of birth.
            WizardStep::DocumentsReview => [
                'photo_path' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /**
     * Cross-field rules that the array-shaped rule table above cannot express.
     * Run separately so the caller gets ONE ValidationException carrying both.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertGuardianInvariants(array $validated): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($validated['guardians'] ?? null) ? array_values($validated['guardians']) : [];

        $errors = [];
        $primaryCount = 0;

        foreach ($rows as $index => $row) {
            $relationship = is_string($row['relationship'] ?? null)
                ? ApplicantRelationship::tryFrom($row['relationship'])
                : null;

            $other = $row['relationship_other'] ?? null;

            if ($relationship?->requiresFreeText() === true && (! is_string($other) || trim($other) === '')) {
                $errors["guardians.{$index}.relationship_other"] = [
                    __('opes.admissions_screen.errors.relationship_other_required'),
                ];
            }

            if ((bool) ($row['is_primary'] ?? false)) {
                $primaryCount++;

                // 7.2: `is_primary = 1` implies `has_custody = 1`, rejected
                // otherwise. Enforced here rather than quietly coerced, so the
                // operator sees the implication instead of discovering later
                // that a box they left clear got ticked for them.
                if (! (bool) ($row['has_custody'] ?? false)) {
                    $errors["guardians.{$index}.has_custody"] = [
                        __('opes.admissions_screen.errors.primary_needs_custody'),
                    ];
                }
            }
        }

        if ($primaryCount !== 1) {
            // "Every active student has exactly one current primary guardian"
            // (7.2). Catching it here means conversion never has to unwind a
            // half-built student because the link table refused the second
            // primary at the very end of the transaction.
            $errors['guardians'] = [__('opes.admissions_screen.errors.exactly_one_primary')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Map validated step input onto model columns. Only keys the step actually
     * owns are returned, so saving step 2 can never blank a step 1 column.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function columnsFor(WizardStep $step, array $validated): array
    {
        if ($step === WizardStep::ParentGuardian) {
            $this->assertGuardianInvariants($validated);

            // Guardian rows live in their own table; nothing on the parent row
            // belongs to this step.
            return [];
        }

        $columns = self::rulesFor($step);
        unset($columns['guardians']);

        $attributes = [];

        foreach (array_keys($columns) as $column) {
            if (str_contains($column, '.')) {
                continue;
            }

            if (array_key_exists($column, $validated)) {
                $value = $validated[$column];
                $attributes[$column] = $value === '' ? null : $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function replaceGuardians(AdmissionApplication $application, array $validated): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($validated['guardians'] ?? null) ? array_values($validated['guardians']) : [];

        // Delete-then-insert rather than a diff. Step 3 submits the complete
        // set every time, positions are dense, and a diff would need a stable
        // client-side identity that a five-row form does not have. Inside the
        // caller's transaction, so a failure leaves the previous set intact.
        AdmissionApplicationGuardian::query()
            ->where('admission_application_id', '=', (int) $application->getKey())
            ->delete();

        foreach ($rows as $index => $row) {
            AdmissionApplicationGuardian::query()->create([
                'admission_application_id' => (int) $application->getKey(),
                'position' => $index + 1,
                'title' => $this->nullableString($row['title'] ?? null),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'gender' => (string) ($row['gender'] ?? ''),
                'date_of_birth' => $this->nullableString($row['date_of_birth'] ?? null),
                'relationship' => (string) ($row['relationship'] ?? ''),
                'relationship_other' => $this->nullableString($row['relationship_other'] ?? null),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'id_type' => $this->nullableString($row['id_type'] ?? null),
                'id_number' => $this->nullableString($row['id_number'] ?? null),
                'occupation' => $this->nullableString($row['occupation'] ?? null),
                'employer' => $this->nullableString($row['employer'] ?? null),
                'phone' => (string) ($row['phone'] ?? ''),
                'alternative_phone' => $this->nullableString($row['alternative_phone'] ?? null),
                'email' => $this->nullableString($row['email'] ?? null),
                'address_line' => $this->nullableString($row['address_line'] ?? null),
                'city' => $this->nullableString($row['city'] ?? null),
                'region' => $this->nullableString($row['region'] ?? null),
                'language' => $this->nullableString($row['language'] ?? null) ?? 'en',
                'has_custody' => (bool) ($row['has_custody'] ?? false),
                'receives_reports' => (bool) ($row['receives_reports'] ?? false),
                'receives_invoices' => (bool) ($row['receives_invoices'] ?? false),
                'is_emergency_contact' => (bool) ($row['is_emergency_contact'] ?? false),
                'is_authorised_for_pickup' => (bool) ($row['is_authorised_for_pickup'] ?? false),
                'is_fee_payer' => (bool) ($row['is_fee_payer'] ?? false),
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'date_of_birth.before' => __('opes.admissions_screen.errors.dob_in_future'),
        ];
    }

    private function currentActor(): Actor
    {
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            // Unlike a scheduled job, an admission is always somebody's act.
            // Falling back to Actor::system() here would file a real person's
            // data entry under "system" in the one log that is meant to say
            // who did it (00-core 14).
            throw new RuntimeException('An admission step cannot be saved by an unauthenticated caller.');
        }

        return $actor;
    }
}
