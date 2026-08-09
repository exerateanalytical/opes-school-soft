<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `DisciplineSanction` — the action taken on a case (docs/plans/phase-08.md
 * F3; 07-students §9.7 counts `detention` and `exclusion` sanctions into the
 * report card's consignes/exclusions line).
 *
 * A `suspension` sanction NEVER writes `enrollments.status` from this
 * module: ApplySanction calls the Students door
 * (`Students\Actions\SuspendEnrollment`) so the enrollment lifecycle,
 * status-transition log and derived student status stay owned by Students.
 *
 * `acknowledged_at` is the guardian-acknowledgement timestamp (07-students
 * §8 row 21, 10-documents DISC guardian signature line) — recorded when the
 * signed slip comes back, NULL until then.
 *
 * FK RESTRICT on the case: a sanction is evidence; deleting casework out
 * from under it is forbidden in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_sanctions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('discipline_case_id')
                ->constrained('discipline_cases')
                ->restrictOnDelete();

            $table->enum('type', [
                'warning', 'detention', 'consigne', 'suspension',
                'exclusion', 'community_service', 'guardian_summons',
            ]);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->foreignId('applied_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('acknowledged_at')->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // §9.7's rollup probe: consignes/exclusions counted by type
            // within an assessment period's date window.
            $table->index(['type', 'starts_on'], 'idx_discipline_sanctions_type_date');
        });

        // Belt beside the Action's braces: a sanction cannot end before it
        // starts. Same CHECK idiom as enrollment_segments.
        DB::statement(
            'ALTER TABLE discipline_sanctions ADD CONSTRAINT chk_discipline_sanctions_range '
            .'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_sanctions');
    }
};
