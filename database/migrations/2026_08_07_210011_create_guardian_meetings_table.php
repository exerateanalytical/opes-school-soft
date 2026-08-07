<?php

declare(strict_types=1);

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md 7.8 - schema only for Phase 2; the profile tab that
 * reads it is a later phase.
 *
 * `student_id` is NULLABLE because a meeting can legitimately concern the
 * guardian rather than one child - a financial arrangement covering three
 * siblings, or an admission interview before any student row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_meetings', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('student_id')->nullable();

            $table->timestamp('scheduled_at');
            $table->timestamp('held_at')->nullable();
            $table->string('location', 160)->nullable();

            $table->enum('meeting_type', MeetingType::values());
            $table->enum('requested_by', MeetingRequestedBy::values());

            $table->text('agenda')->nullable();

            // 7.8 asks for "JSON of staff ids + free-text names, with a
            // MeetingAttendee child table for staff so the FK is real". Phase 2
            // ships the JSON only: the child table's FK targets `staff_members`,
            // which another agent is still writing to in this same phase, and
            // adding a constraint against a table under concurrent migration is
            // how a merge produces an unrunnable migration set. The child table
            // lands in the Phase 11 (HR) consolidation pass, alongside the
            // deferred `class_groups.class_teacher_staff_id` FK which is
            // deferred for exactly the same reason. Until then this column is
            // the record, and it is not authoritative for staff attribution.
            $table->json('attendees')->nullable();

            $table->text('minutes')->nullable();
            $table->text('decisions')->nullable();

            $table->string('follow_up_action', 255)->nullable();
            $table->date('follow_up_due_on')->nullable();
            $table->enum('follow_up_status', FollowUpStatus::values())->default(FollowUpStatus::None->value);

            $table->enum('status', MeetingStatus::values())->default(MeetingStatus::Scheduled->value);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['guardian_id', 'scheduled_at'], 'idx_gm_guardian_scheduled');
            $table->index(['student_id', 'scheduled_at'], 'idx_gm_student_scheduled');
            // Drives the "outstanding follow-ups" list, which is the only
            // reason the follow-up columns are worth storing at all.
            $table->index(['follow_up_status', 'follow_up_due_on'], 'idx_gm_follow_up');

            $table->foreign('guardian_id', 'fk_gm_guardian')
                ->references('id')->on('guardians')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('student_id', 'fk_gm_student')
                ->references('id')->on('students')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('created_by', 'fk_gm_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_meetings');
    }
};
