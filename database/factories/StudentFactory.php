<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
final class StudentFactory extends Factory
{
    /** @var class-string<Student> */
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement(Gender::cases());

        // matricule and admission_no are globally UNIQUE, so the factory takes
        // them from fake()->unique() rather than a random string: a collision
        // here would surface as a mystifying failure in an unrelated test.
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'matricule' => sprintf('TMP/2026/%06d', $sequence),
            'matricule_is_official' => false,
            'admission_no' => sprintf('ADM/2026/%06d', $sequence),
            'first_name' => fake()->firstName($gender === Gender::Male ? 'male' : 'female'),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'preferred_name' => null,
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-5 years')->format('Y-m-d'),
            'birth_certificate_no' => null,
            'place_of_birth' => fake()->city(),
            'gender' => $gender,
            'nationality' => 'CM',
            'state_of_origin' => fake()->randomElement([
                'Centre', 'Littoral', 'North West', 'South West', 'West', 'Far North',
            ]),
            'religion' => null,
            'blood_group' => null,
            'genotype' => null,
            'national_id_number' => null,
            'national_id_blind_index' => null,
            'photo_path' => null,
            'phone' => null,
            'email' => null,
            'address_line' => null,
            'city' => null,
            'region' => null,
            'house_id' => null,
            'status' => StudentStatus::Prospective,
            'first_admission_date' => '2026-09-05',
            'left_on' => null,
            'deceased_on' => null,
            'is_archived' => false,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * A student whose matricule has already been finalised - the state in
     * which 6.4's observer refuses any further write to the column.
     */
    public function withOfficialMatricule(string $matricule = 'HA/2026/00001'): self
    {
        return $this->state(fn (): array => [
            'matricule' => $matricule,
            'matricule_is_official' => true,
        ]);
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => StudentStatus::Active]);
    }
}
