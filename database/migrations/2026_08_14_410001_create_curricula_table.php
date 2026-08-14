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
        // Gap #2 of docs/specs/2026-08-12-module-gap-analysis.md: the
        // curriculum entity. One row is one VERSION of the programme for a
        // (subject, class level, sub-system) triple, valid for an academic
        // year. A published row is immutable - a change is a new row with
        // version + 1 (ReviseCurriculum), so anything that referenced the
        // old version keeps meaning what it meant.
        Schema::create('curricula', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT throughout: a curriculum pins the identity of what is
            // taught; the referenced structure is archived, never deleted
            // (00-core 10.5).
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();

            // The academic-year validity window: the year this version is
            // (or was) the programme of study for.
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            // Academics\Domain\SubSystem values ('anglophone'/'francophone').
            // A Domain enum, not a Model, so importing it across the module
            // boundary is permitted (ModuleBoundaryTest forbids Models only).
            // Structural, not cosmetic: the two sub-systems teach different
            // programmes for the "same" subject at the "same" rung.
            $table->string('sub_system', 20);

            $table->string('title', 160);
            $table->string('description', 500)->nullable();

            // Versioning: starts at 1, ReviseCurriculum clones published ->
            // version + 1 draft. Never reused, never edited once published.
            $table->unsignedSmallInteger('version')->default(1);

            $table->enum('status', ['draft', 'published'])->default('draft');

            // Stamped by PublishCurriculum, never cleared: locking is one-way.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // One row per identity + version: the identity is the (subject,
            // level, sub-system, year) tuple; version disambiguates history.
            $table->unique(
                ['subject_id', 'class_level_id', 'sub_system', 'academic_year_id', 'version'],
                'uq_curricula_identity_version'
            );

            $table->index(['status'], 'ix_curricula_status');
        });

        // A version below 1 would break the "revise = max + 1" contract
        // without failing anywhere visible.
        DB::statement(
            'ALTER TABLE curricula ADD CONSTRAINT chk_curricula_version CHECK (version >= 1)'
        );

        // A published row must carry its publication stamp; a draft must not.
        DB::statement(
            "ALTER TABLE curricula ADD CONSTRAINT chk_curricula_published_stamp CHECK ("
            ."(status = 'published' AND published_at IS NOT NULL) OR "
            ."(status = 'draft' AND published_at IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};
