<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The two assignment sources Mark::mayEnter() resolves (01-assessment §7.5):
 * a teacher is the ASSIGNED teacher for an allocation, OR an ACTIVE DELEGATION
 * names them. Until this migration, neither table existed and the gate - built
 * schema-guarded, denying when it cannot tell - refused every plain Teacher.
 * That was the correct failure direction for a privacy gate, and also meant
 * the marks module could not do its primary job. This closes the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_allocation_teachers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('subject_allocation_id')->constrained('subject_allocations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['subject_allocation_id', 'user_id'], 'uq_sat_allocation_user');
            $table->index('user_id', 'ix_sat_user');
        });

        Schema::create('mark_entry_delegations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('subject_allocation_id')->constrained('subject_allocations')->restrictOnDelete();

            // §7.5's shape. class_group_id / assessment_period_id narrow the
            // delegation's scope on paper (they are printed in the publication
            // dossier); the gate itself resolves by allocation, which is the
            // unit marks attach to.
            $table->foreignId('class_group_id')->nullable()->constrained('class_groups')->restrictOnDelete();
            $table->unsignedBigInteger('assessment_period_id')->nullable();

            // delegate_user_id, not staff_id: marks are entered by Users, and
            // Mark::mayEnter() resolves auth()->id() against exactly this
            // column - the contract this table exists to satisfy.
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['delegate_user_id', 'valid_from', 'valid_to'], 'ix_med_delegate_window');
            $table->index('subject_allocation_id', 'ix_med_allocation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_entry_delegations');
        Schema::dropIfExists('subject_allocation_teachers');
    }
};
