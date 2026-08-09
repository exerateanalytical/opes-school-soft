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
        // docs/plans/phase-10.md §3 row 10 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). The gate register: who is inside the
        // fence right now, and the historical trail of every visit. A row is
        // "on site" exactly while checked_out_at is NULL.
        Schema::create('visitor_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('visitor_name');
            $table->string('phone', 32);

            // A national ID / passport reference is identity data about a
            // private individual: encrypted at the model ('encrypted' cast,
            // the StudentMedicalRecord.detail pattern). TEXT because the
            // ciphertext envelope is far wider than the plaintext.
            $table->text('id_document_ref')->nullable();

            $table->string('purpose');

            // Who the visitor came to see. host_id is a users.id for staff,
            // a students.id for student - polymorphic across modules, so NO
            // FK; existence is validated in CheckInVisitor via DB::table.
            // Office visits (bursar's desk, admissions...) carry no host row.
            $table->enum('host_type', ['staff', 'student', 'office'])->default('office');
            $table->unsignedBigInteger('host_id')->nullable();

            $table->string('badge_no', 32);

            $table->timestamp('checked_in_at');
            // NULL while the visitor is still on site.
            $table->timestamp('checked_out_at')->nullable();

            $table->string('gate_pass_no', 64)->nullable();

            $table->foreignId('logged_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // ---------------------------------------------------------------
            // A physical badge hangs on ONE neck at a time: badge_no must be
            // unique among currently-checked-in visitors, while yesterday's
            // rows may reuse it freely. Enforced by the schema rather than
            // trusted to Action discipline - the 00-core 10.1 NULL-unique
            // generated-column stand-in for a partial index (same trick as
            // transport_allocations.active_key and the HANDOVER
            // invoice-idempotency fix). The column carries badge_no only
            // while the visitor is on site.
            // ---------------------------------------------------------------
            $table->string('active_badge_key', 32)
                ->nullable()
                ->storedAs('CASE WHEN `checked_out_at` IS NULL THEN `badge_no` END');

            $table->unique('active_badge_key', 'uq_visitor_logs_active_badge');

            // The desk's "who is here today" read and the daily report.
            $table->index('checked_in_at', 'idx_visitor_logs_checked_in_at');
        });

        // A check-out before the check-in would make the visit length
        // negative and the day report unreadable.
        DB::statement(
            'ALTER TABLE visitor_logs ADD CONSTRAINT chk_visitor_logs_range '
            .'CHECK (checked_out_at IS NULL OR checked_out_at >= checked_in_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_logs');
    }
};
