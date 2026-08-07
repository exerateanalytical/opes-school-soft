<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Models\AdmissionApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default state is a BLANK step-1 draft, not a ready-to-convert
 * application. That is deliberate: the interesting failure modes in
 * docs/specs/07-students.md 6.2 are all about half-finished forms, and a
 * factory whose default is "complete" makes the incomplete cases the awkward
 * ones to write - so they do not get written.
 *
 * Use ->complete() for the fully-filled draft the conversion tests need.
 *
 * @extends Factory<AdmissionApplication>
 */
class AdmissionApplicationFactory extends Factory
{
    /** @var class-string<AdmissionApplication> */
    protected $model = AdmissionApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => ApplicationStatus::Draft,
            'current_step' => WizardStep::FIRST,
            'completed_step' => 0,
            'nationality' => 'CM',
        ];
    }

    /** Step 1 passed: the identity block is filled in. */
    public function withIdentity(): self
    {
        return $this->state(fn (): array => [
            'first_name' => 'Ncham',
            'middle_name' => 'Andre',
            'last_name' => 'Bela',
            'date_of_birth' => '2012-03-15',
            'gender' => 'male',
            'nationality' => 'CM',
            'place_of_birth' => 'Douala',
            'state_of_origin' => 'Littoral',
            'religion' => 'Christianity',
            'blood_group' => 'O+',
            'genotype' => 'AA',
            'completed_step' => WizardStep::BasicInformation->value,
            'current_step' => WizardStep::AcademicDetails->value,
        ]);
    }

    /**
     * Steps 1, 2 and 4 passed. Step 3 is NOT covered here - the guardian rows
     * live in their own table and a factory that quietly inserted them would
     * hide the "exactly one primary guardian" invariant the tests exist to
     * exercise.
     */
    public function complete(int $academicYearId, int $classLevelId, string $admissionDate): self
    {
        return $this->withIdentity()->state(fn (): array => [
            'academic_year_id' => $academicYearId,
            'class_level_id' => $classLevelId,
            'admission_date' => $admissionDate,
            'completed_step' => WizardStep::LAST,
            'current_step' => WizardStep::LAST,
        ]);
    }

    public function submitted(string $applicationNo = 'APP/2026-2027/0001'): self
    {
        return $this->state(fn (): array => [
            'application_no' => $applicationNo,
            'status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
            'completed_step' => WizardStep::LAST,
            'current_step' => WizardStep::LAST,
        ]);
    }
}
