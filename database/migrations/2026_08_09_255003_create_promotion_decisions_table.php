<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/plans/phase-07.md decision 4 - the minimal promotion-decision record
 * the rollover wizard's step 6 consumes (docs/specs/08-operations.md §6.2:
 * "Consumes the promotion decisions from 07-students; refuses if any class
 * group has undecided students"). Owned by the Students module; Phase 8's
 * full promotion engine builds ON this table, not around it.
 *
 * One decision per enrollment, by constraint. `target_class_group_key` is a
 * plain string key (e.g. "level:12" or "group:Form 2 A") rather than an FK:
 * at decision time the destination class group in the NEW year may not exist
 * yet - step 2 creates it, step 6 resolves the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_decisions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->enum('decision', ['promoted', 'repeat', 'graduated', 'withdrawn']);
            $table->string('target_class_group_key', 120)->nullable();

            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');

            $table->timestamps();

            $table->unique('enrollment_id', 'uq_promotion_decisions_enrollment');
            $table->index(['decision'], 'ix_promotion_decisions_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_decisions');
    }
};
