<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `DisciplineCategory` — the offence catalogue (docs/plans/phase-08.md F3,
 * design doc "Discipline" outline).
 *
 * A case never free-texts its offence: it points at a catalogued category so
 * the sanction ladder, the promotion `discipline` criterion (07-students
 * §10.4) and the conduct block all count the same thing. `severity` is the
 * scalar those consumers compare (1 = minor … 5 = gravest) and
 * `default_sanction_type` is only the pre-selected suggestion in the Apply
 * Sanction form — advisory, like the ladder itself.
 *
 * Bilingual names because the category prints on MINESEC-side documents
 * (10-documents DISC series) exactly as catalogued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('name', 120)->unique();
            $table->string('name_fr', 120)->nullable();

            // 1 (minor) … 5 (gravest). The read door aggregates MAX(severity)
            // per enrollment for the promotion criterion.
            $table->unsignedTinyInteger('severity')->default(1);

            // Mirrors discipline_sanctions.type; NULL = the form pre-selects
            // nothing. A plain string column, not an ENUM, so the two enums
            // cannot drift apart in DDL — the PHP enum is the authority.
            $table->string('default_sanction_type', 30)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_categories');
    }
};
