<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Parent-Teacher Association: a body with its own officers and general
 * meetings, distinct from `guardian_meetings` (a single guardian meeting the
 * school about one student's disciplinary/financial/admission matter).
 *
 * Not in docs/specs - the spec set is compliance-first (SYSCOHADA, DGI,
 * MINESEC) and never mentions the PTA as an institution.
 *
 * `pta_officers` is a small reference list rather than free text on the
 * meeting: an association keeps a President, Secretary, Treasurer etc. as
 * standing roles across meetings, and `term_ends_on` NULL means currently
 * serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pta_officers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $table->string('office', 60);
            $table->date('term_starts_on');
            $table->date('term_ends_on')->nullable();

            $table->timestamps();

            $table->index(['office', 'term_ends_on'], 'ix_pta_officers_office_term');
        });

        Schema::create('pta_meetings', function (Blueprint $table): void {
            $table->id();

            $table->string('title', 200);
            $table->date('meeting_date');
            $table->string('location', 200)->nullable();

            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->integer('attendee_count')->nullable();

            $table->string('status', 16)->default('scheduled');

            $table->foreignId('chaired_by_officer_id')->nullable()
                ->constrained('pta_officers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('meeting_date', 'ix_pta_meetings_date');
        });

        DB::statement(
            "ALTER TABLE pta_meetings ADD CONSTRAINT ck_pta_meetings_status "
            ."CHECK (status IN ('scheduled','held','cancelled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pta_meetings');
        Schema::dropIfExists('pta_officers');
    }
};
