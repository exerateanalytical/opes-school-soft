<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /** @var class-string<Department> */
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'D-'.Str::upper(Str::random(6)),
            'name' => 'Department of '.fake()->word(),
            'name_fr' => 'Departement de '.fake()->word(),
            'head_staff_id' => null,
        ];
    }
}
