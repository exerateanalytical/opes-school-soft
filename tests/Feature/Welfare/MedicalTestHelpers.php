<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\RecordConsultation;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Models\MedicalConsultation;
use App\Support\Audit\Actor;
use Database\Factories\EnrollmentFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 10 W3 medical suite. Prefix p10Medical,
 * every helper function_exists-guarded (00-core test discipline; names must
 * never collide with another agent's).
 */
if (! function_exists('p10MedicalUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p10MedicalUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p10MedicalNurse')) {
    /** The usual operator: holds both medical abilities (the Nurse seed). */
    function p10MedicalNurse(): User
    {
        return p10MedicalUser(MedicalPermission::VIEW, MedicalPermission::MANAGE);
    }
}

if (! function_exists('p10MedicalActor')) {
    function p10MedicalActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p10MedicalStudentId')) {
    /** A bare student row (no enrollment), via DB like EnrollmentFactory. */
    function p10MedicalStudentId(): int
    {
        $suffix = Str::upper(Str::random(8));

        return (int) DB::table('students')->insertGetId([
            'matricule' => 'OS-26-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2026/'.$suffix,
            'first_name' => 'Medic',
            'last_name' => 'Case '.$suffix,
            'date_of_birth' => '2013-02-19',
            'place_of_birth' => 'Bamenda',
            'gender' => 'female',
            'nationality' => 'CM',
            'status' => 'active',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p10MedicalEnrolledStudent')) {
    /**
     * An enrolled student: [student_id, enrollment_id].
     *
     * @return array{0: int, 1: int}
     */
    function p10MedicalEnrolledStudent(): array
    {
        $enrollment = EnrollmentFactory::new()->createOne();

        return [(int) $enrollment->student_id, (int) $enrollment->getKey()];
    }
}

if (! function_exists('p10MedicalConsultation')) {
    /** A consultation recorded through the REAL door. */
    function p10MedicalConsultation(
        User $user,
        int $studentId,
        ?int $enrollmentId = null,
        string $complaint = 'Fever and headache since morning assembly',
        ConsultationSeverity $severity = ConsultationSeverity::Low,
        ConsultationOutcome $outcome = ConsultationOutcome::ReturnedToClass,
        ?Carbon $visitedAt = null,
    ): MedicalConsultation {
        return app(RecordConsultation::class)->handle(
            $studentId,
            $enrollmentId,
            $visitedAt ?? Carbon::now(),
            $complaint,
            'Suspected malaria',
            'Paracetamol 500mg, observation',
            $severity,
            $outcome,
            p10MedicalActor($user),
        );
    }
}
