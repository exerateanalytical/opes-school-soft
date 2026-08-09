<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Operations\Models\Licence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The payload/signature here are STRUCTURALLY plausible but cryptographically
 * meaningless - schema fixtures only. Verification tests (F4 workstream)
 * generate a throwaway key pair in memory and sign real canonical JSON; no
 * private key of any kind lives in this repository (08-operations §4.1).
 *
 * @extends Factory<Licence>
 */
class LicenceFactory extends Factory
{
    /** @var class-string<Licence> */
    protected $model = Licence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payload' => [
                'product' => 'opes-school',
                'edition' => 'core',
                'school' => 'Test School '.fake()->unique()->numberBetween(1, 99_999),
                'expires_at' => '2028-08-31',
                'student_cap' => 600,
                'section_count' => 2,
            ],
            'signature' => base64_encode(random_bytes(64)),
            'fingerprint' => null,
            'source' => Licence::SOURCE_FILE,
            'expires_at' => '2028-08-31',
            'next_check_after' => null,
            'grace_days' => 30,
            'revoked_at' => null,
        ];
    }

    public function activated(): static
    {
        return $this->state(fn (): array => [
            'source' => Licence::SOURCE_ACTIVATION,
            'fingerprint' => hash('sha256', 'opes-machine-fingerprint-v1|'.fake()->uuid()),
            'next_check_after' => '2027-03-01 00:00:00',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => '2027-01-15 09:00:00',
        ]);
    }
}
