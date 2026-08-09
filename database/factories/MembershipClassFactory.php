<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Library\Models\MembershipClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Student-shaped borrowing terms: 2 concurrent issues, 14-day loans, one
 * 7-day renewal, 200 FCFA/day fine after 0 grace days, capped at the
 * book's replacement cost (06-assets-stores §10.3/§10.5).
 *
 * @extends Factory<MembershipClass>
 */
class MembershipClassFactory extends Factory
{
    /** @var class-string<MembershipClass> */
    protected $model = MembershipClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'MC'.fake()->unique()->numberBetween(1, 999_999);

        return [
            'code' => $code,
            'name' => 'Membership '.$code,
            'name_fr' => 'Adhesion '.$code,
            'max_concurrent_issues' => 2,
            'loan_days' => 14,
            'max_renewals' => 1,
            'renewal_days' => 7,
            'fine_per_day' => 200,
            'fine_grace_days' => 0,
            'fine_cap_policy' => 'replacement_cost',
            'blocking_fine_threshold' => 0,
            'max_reservations' => 1,
            'can_borrow_reference' => false,
            'is_archived' => false,
        ];
    }
}
