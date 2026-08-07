<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffMember>
 */
class StaffMemberFactory extends Factory
{
    /** @var class-string<StaffMember> */
    protected $model = StaffMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_no' => 'STF-'.Str::upper(Str::random(8)),
            'first_name' => 'Ngwa',
            'last_name' => 'Bertrand',
            'other_names' => null,
            'gender' => 'male',
            'date_of_birth' => '1988-04-12',
            'phone' => '+237 6'.random_int(10000000, 99999999),
            // Left null by default: the column is uniquely indexed and most
            // staff records start without an address on file.
            'email' => null,
            'photo_path' => null,
            'status' => 'active',
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'inactive']);
    }
}
