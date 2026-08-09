<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\Commune;
use App\Modules\Payroll\Models\EmployerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployerProfile>
 *
 * The factory fills the NOT NULL identity columns with obviously synthetic
 * values; `proration_basis`, `ceiling_prorates_partial_month` stay NULL by
 * default - exactly as the wizard leaves them until the customer decides
 * (docs/specs/05-hr-payroll.md 2.4) - and tests opt into them per case.
 */
class EmployerProfileFactory extends Factory
{
    /** @var class-string<EmployerProfile> */
    protected $model = EmployerProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cnps_employer_number' => 'CNPS-EMP-'.fake()->unique()->numerify('######'),
            'dipe_number' => 'DIPE-'.fake()->unique()->numerify('######'),
            'niu' => 'M'.fake()->unique()->numerify('############'),
            'dgi_centre' => 'CIME',
            'tdl_commune_id' => Commune::factory(),
            'cnps_regime' => 'enseignement_prive',
            'rp_risk_class' => 'A',
            'cnps_notification_document_id' => fake()->numberBetween(1, 1_000_000),
            'cnps_notification_reference' => 'NOTIF-'.fake()->unique()->numerify('####'),
            'proration_basis' => null,
            'ceiling_prorates_partial_month' => null,
            'irpp_mode' => 'ytd_cumulative',
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'created_by' => User::factory(),
        ];
    }
}
