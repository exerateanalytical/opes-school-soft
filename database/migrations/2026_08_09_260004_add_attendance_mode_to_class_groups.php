<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md §9.7–§9.8: whether a class group takes one
 * register per day or one per lesson. `daily` is the default because
 * per-lesson is an 8× row multiplier (§9.8) — enabling it is a decision,
 * not a default. SetClassGroupAttendanceMode rejects `daily` for class
 * groups whose assessment framework requires per-lesson attendance (the
 * MINESEC heures-d'absence bulletin blocks would print blank).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table): void {
            $table->enum('attendance_mode', ['daily', 'per_lesson'])
                ->default('daily')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table): void {
            $table->dropColumn('attendance_mode');
        });
    }
};
