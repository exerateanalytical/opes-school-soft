<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Support\Clock\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<StudentGuardian>
 *
 * `student_id` is deliberately NOT defaulted. The Students module owns that
 * table and its factory; this module may not import either (00-core 6.2, and
 * tests/Architecture/ModuleBoundaryTest.php). Callers pass a student id they
 * created themselves - in this module's tests, with a plain DB::table insert.
 * A missing student_id therefore fails as a NOT NULL violation at insert time,
 * which is louder and more honest than a factory quietly inventing a row in
 * another module's table.
 */
class StudentGuardianFactory extends Factory
{
    /** @var class-string<StudentGuardian> */
    protected $model = StudentGuardian::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guardian_id' => Guardian::factory(),
            'relationship' => GuardianRelationship::Mother,
            'relationship_other' => null,
            'is_primary' => false,
            'has_custody' => false,
            'receives_reports' => false,
            'receives_invoices' => false,
            'is_emergency_contact' => false,
            'is_authorised_for_pickup' => false,
            'is_fee_payer' => false,
            // All flags off by default: deny-by-default in fixture form. A
            // test that forgets to grant something must fail closed, never
            // pass because the factory was generous.
            'valid_from' => BusinessDate::today(),
            'valid_to' => null,
            'revocation_reason' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /** The primary guardian, which 7.2 requires to hold custody. */
    public function primary(): self
    {
        return $this->state(fn (): array => [
            'is_primary' => true,
            'has_custody' => true,
        ]);
    }

    /** @param  array<string, bool>  $flags */
    public function withFlags(array $flags): self
    {
        return $this->state(fn (): array => $flags);
    }

    /** A link that has already been revoked: valid, but not any more. */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'valid_from' => Carbon::parse(BusinessDate::today())->subDays(30)->toDateString(),
            'valid_to' => Carbon::parse(BusinessDate::today())->subDay()->toDateString(),
            'revocation_reason' => 'Custody transferred by court order.',
        ]);
    }

    /** A link dated to start tomorrow; 7.3's first clause says it grants nothing. */
    public function notYetEffective(): self
    {
        return $this->state(fn (): array => [
            'valid_from' => Carbon::parse(BusinessDate::today())->addDay()->toDateString(),
        ]);
    }
}
