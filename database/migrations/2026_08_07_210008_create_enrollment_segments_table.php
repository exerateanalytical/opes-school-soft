<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // C2 (07-students 5). One Enrollment per student-year owns all marks,
        // attendance, invoices and discipline. Segments carry the class group
        // and a date range; a mid-year transfer closes one segment and opens
        // another and NEVER creates a second Enrollment. That is the whole
        // fix for "mid-year transfer loses marks".
        Schema::create('enrollment_segments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained('class_groups')->restrictOnDelete();

            $table->date('starts_on');
            // NULL = open. The open segment is the one that resolves "what
            // class is this student in today".
            $table->date('ends_on')->nullable();

            $table->unsignedSmallInteger('roll_number')->nullable();

            $table->enum('reason', [
                'initial',
                'class_transfer',
                'stream_change',
                'group_rebalance',
                'correction',
            ])->default('initial');

            // Mandatory for `correction`; enforced in the Actions.
            $table->string('reason_text', 255)->nullable();

            // 4.2 invariant 7: over-capacity requires an explicit permissioned
            // override, recorded with a reason on the segment.
            $table->boolean('capacity_override')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // ---------------------------------------------------------------
            // 5.2. `open_key` is the 00-core 10.1 partial-index stand-in:
            // it carries enrollment_id while the segment is open and NULL once
            // closed, so UNIQUE(open_key) means "at most ONE open segment per
            // enrollment" while permitting any number of closed ones.
            //
            // Together with the Action-side rule that a new segment starts the
            // day AFTER the previous one ends, this is what makes overlap
            // structurally impossible: at any instant there is at most one
            // segment without an end date, and every closed segment ends
            // strictly before its successor begins.
            // ---------------------------------------------------------------
            $table->unsignedBigInteger('open_key')
                ->nullable()
                ->storedAs('CASE WHEN `ends_on` IS NULL THEN `enrollment_id` END');

            $table->unique('open_key', 'uq_segment_open');

            // 5.1: roll_number is "unique per open segment set". Same pattern
            // again - the key is the class group only while the segment is
            // open AND a roll number is actually assigned, so historical rolls
            // and unnumbered segments never collide.
            $table->unsignedBigInteger('open_roll_group_key')
                ->nullable()
                ->storedAs(
                    'CASE WHEN `ends_on` IS NULL AND `roll_number` IS NOT NULL '
                    .'THEN `class_group_id` END'
                );

            $table->unique(['open_roll_group_key', 'roll_number'], 'uq_segment_roll');

            // 07-students 13. The second index is what 08-operations'
            // enrollments(class_group_id, academic_year_id, status) becomes,
            // now that class_group_id no longer lives on Enrollment.
            $table->index(['class_group_id', 'starts_on', 'ends_on'], 'idx_segments_group_range');
            $table->index(['enrollment_id', 'starts_on'], 'idx_segments_enrollment_start');
        });

        // 5.2. A zero-or-negative-length segment would make "the segment
        // covering a date" ambiguous, and 5.3 hangs rank and class statistics
        // on exactly that lookup.
        DB::statement(
            'ALTER TABLE enrollment_segments ADD CONSTRAINT chk_enrollment_segments_range '
            .'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_segments');
    }
};
