<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Guardians\Domain\Gender;
use App\Modules\Guardians\Domain\GuardianIdType;
use App\Modules\Guardians\Domain\GuardianLanguage;
use App\Modules\Guardians\Domain\GuardianStatus;
use App\Modules\Guardians\Domain\MaritalStatus;
use App\Modules\Guardians\Domain\PhoneNumber;
use App\Modules\Guardians\Domain\PreferredContactMethod;
use App\Modules\Guardians\Domain\ResidentialStatus;
use App\Modules\Guardians\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
{
    /** @var class-string<Guardian> */
    protected $model = Guardian::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Already E.164, because the duplicate detector's tier 2 compares the
        // stored column exactly and a factory that wrote raw local formats
        // would make every test on that tier pass or fail for the wrong
        // reason.
        $phone = PhoneNumber::normalise('6'.fake()->numerify('########'));

        return [
            'guardian_no' => 'GRD-'.Str::upper(Str::random(8)),
            'title' => fake()->randomElement(['Mr.', 'Mrs.', 'Dr.']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-65 years', '-25 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Gender::cases()),
            'nationality' => 'CM',
            'id_type' => GuardianIdType::NationalId,
            // Null by default. `id_number_blind_index` is UNIQUE, so a factory
            // that always produced one would make any test creating two
            // guardians collide unless it happened to randomise well - a flaky
            // failure with a confusing message. Tests that exercise tier-1
            // duplicate detection set it explicitly, which is where it belongs.
            'id_number' => null,
            'id_number_blind_index' => null,
            'occupation' => fake()->jobTitle(),
            'employer' => fake()->company(),
            'marital_status' => fake()->randomElement(MaritalStatus::cases()),
            'phone' => $phone,
            'alternative_phone' => null,
            'email' => fake()->unique()->safeEmail(),
            'address_line' => fake()->streetAddress(),
            'city' => 'Douala',
            'region' => 'Littoral',
            'country' => 'Cameroon',
            'residential_status' => fake()->randomElement(ResidentialStatus::cases()),
            'preferred_contact_method' => PreferredContactMethod::Phone,
            'language' => fake()->randomElement(GuardianLanguage::cases()),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => PhoneNumber::normalise('6'.fake()->numerify('########')),
            'emergency_contact_relationship' => 'Sibling',
            'emergency_contact_address' => fake()->streetAddress(),
            'photo_path' => null,
            'status' => GuardianStatus::Active,
            'notify_sms' => true,
            'notify_email' => false,
            'notify_push' => false,
            'receives_reports' => true,
            'receives_invoices' => true,
            'portal_user_id' => null,
            'is_archived' => false,
        ];
    }

    /**
     * A deactivated guardian. 7.5 makes `status = 'active'` a conjunctive gate
     * on every row of the matrix, so this state has to be reachable in a test.
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => GuardianStatus::Inactive]);
    }

    public function withIdNumber(string $idNumber): self
    {
        return $this->state(fn (): array => [
            'id_number' => $idNumber,
            'id_number_blind_index' => Guardian::blindIndexFor($idNumber),
        ]);
    }
}
