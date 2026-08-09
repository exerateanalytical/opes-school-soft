<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Operations\Models\RolloverBalanceCarry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The student row is inserted with DB::table() rather than through the
 * Students factories: this factory belongs to the Operations workstream and
 * must not depend on another module's factory code to stay green (the
 * EnrollmentFactory precedent).
 *
 * @extends Factory<RolloverBalanceCarry>
 */
class RolloverBalanceCarryFactory extends Factory
{
    /** @var class-string<RolloverBalanceCarry> */
    protected $model = RolloverBalanceCarry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rollover_run_id' => RolloverRunFactory::new(),
            'student_id' => fn (): int => $this->studentId(),
            'kind' => RolloverBalanceCarry::KIND_CREDIT_CARRY,
            'amount' => fake()->numberBetween(1_000, 250_000),
            'journal_entry_id' => null,
        ];
    }

    public function block(): static
    {
        return $this->state(fn (): array => [
            'kind' => RolloverBalanceCarry::KIND_BLOCK,
        ]);
    }

    private function studentId(): int
    {
        $suffix = Str::upper(Str::random(8));

        return DB::table('students')->insertGetId([
            'matricule' => 'OS-27-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2027/'.$suffix,
            'first_name' => 'Carry',
            'last_name' => 'Student '.$suffix,
            'date_of_birth' => '2011-06-02',
            'place_of_birth' => 'Buea',
            'gender' => 'female',
            'nationality' => 'CM',
            'status' => 'active',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
