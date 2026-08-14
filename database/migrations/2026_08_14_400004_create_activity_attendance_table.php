<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_attendance', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('session_id')->constrained('activity_sessions')->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('activity_memberships')->restrictOnDelete();

            $table->enum('status', ['present', 'absent', 'excused']);

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // One mark per member per session: re-recording is an UPDATE by
            // schema, never a second row that would make the register
            // ambiguous.
            $table->unique(['session_id', 'membership_id'], 'uq_activity_attendance_once');

            $table->index('membership_id', 'idx_activity_attendance_membership');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_attendance');
    }
};
