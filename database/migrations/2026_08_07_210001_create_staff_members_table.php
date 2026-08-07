<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff directory record, and nothing more. Contracts, grades, payroll and
 * the rest of HR belong to Phase 11 (docs/specs/11-hr.md); this table exists so
 * that a person can be named - most immediately as a class group's class
 * teacher.
 *
 * `class_groups.class_teacher_staff_id` stays a plain nullable column for now:
 * retrofitting the foreign key means an ALTER on a table other agents are
 * still writing to, so the constraint is added in the Phase 11 consolidation
 * pass, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_members', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The school's own staff number, printed on ID cards and quoted on
            // paper - unique, and never reused.
            $table->string('staff_no', 50)->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('other_names', 150)->nullable();

            $table->enum('gender', ['male', 'female']);
            $table->date('date_of_birth')->nullable();

            $table->string('phone', 30);

            // A plain unique index, not a composite trick: MySQL treats every
            // NULL as distinct, so any number of staff may have no e-mail
            // while two staff may never share one.
            $table->string('email', 255)->nullable()->unique();

            $table->string('photo_path', 255)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_members');
    }
};
