<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 2 / 4.6 / 15 - the school's document-rendering
 * profile: state header text, document language policy, branding slots and
 * the statutory-book cote-et-paraphe reference.
 *
 * This codebase's SchoolProfile is a key-value Setting store with no row
 * table, so - exactly like fiscal_identities (Phase 5) - the document fields
 * get their own singleton table, CHECK (id = 1) making a second row a
 * database error. Fiscal identity (NIU, RCCM, ministry accreditation) is NOT
 * duplicated here: it already lives in `fiscal_identities`, and 10-documents
 * 4.7 renders it from there; a second copy would let the receipt disagree
 * with the tax declaration.
 *
 * BRANDING ALLOW-LIST AS SCHEMA (2.3): the five *_path columns below are the
 * complete set of branding slots the product can hold - crest, logo,
 * principal signature, registrar signature, school stamp. There is no
 * ministry-seal column and no generic slots table, so the forbidden emblem
 * (2.2) has nowhere to live. That absence is the enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_document_profiles', function (Blueprint $table): void {
            // NOT auto-increment: MySQL refuses a CHECK on an auto-increment
            // column, and the singleton CHECK below is the point. The row is
            // always written with an explicit id = 1.
            $table->unsignedBigInteger('id')->primary();

            // ---- state_header block (2.1): text only, per side of the
            // bilingual letterhead. No seal, no coat of arms - there is no
            // column for one.
            $table->boolean('state_header_enabled')->default(false);
            $table->string('ministry_en', 160)->nullable();
            $table->string('ministry_fr', 160)->nullable();
            $table->string('regional_delegation_en', 160)->nullable();
            $table->string('regional_delegation_fr', 160)->nullable();
            $table->string('divisional_delegation_en', 160)->nullable();
            $table->string('divisional_delegation_fr', 160)->nullable();

            // ---- language policy (4.6). Resolution order at render:
            // explicit request -> SchoolSection.document_language -> this.
            $table->enum('default_document_language', ['en', 'fr'])->default('en');

            // true = render both languages side-by-side where the layout
            // permits, stacked otherwise.
            $table->boolean('bilingual_documents')->default(false);

            // ---- branding slots, the 2.3 allow-list and NOTHING else.
            $table->string('crest_path', 255)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('principal_signature_path', 255)->nullable();
            $table->string('registrar_signature_path', 255)->nullable();
            $table->string('school_stamp_path', 255)->nullable();

            // ---- statutory books footer (15): the cote et paraphe
            // reference the Livre-journal and friends must carry.
            $table->string('books_cote_paraphe_reference', 120)->nullable();
            $table->string('paraphe_authority', 160)->nullable();
            $table->date('paraphe_date')->nullable();

            $table->timestamps();
        });

        // Singleton: the only legal primary key value is 1, so a second row
        // is a constraint violation, not an application bug to hunt.
        DB::statement(
            'ALTER TABLE school_document_profiles '
            .'ADD CONSTRAINT chk_school_document_profile_singleton CHECK (id = 1)'
        );

        // NOTHING SEEDED - the row is created by the settings screen's first
        // save, audited, never by a migration.

        // Per-section overrides (2.1: a bilingual school's MINEDUB nursery
        // and MINESEC secondary sit under different ministries; 4.6: an
        // Anglophone secondary and a Francophone nursery print in different
        // languages from the same operator session). NULL = inherit the
        // school-level value above.
        Schema::table('school_sections', function (Blueprint $table): void {
            $table->enum('document_language', ['en', 'fr'])->nullable();
            $table->boolean('state_header_enabled')->nullable();
            $table->string('state_header_ministry_en', 160)->nullable();
            $table->string('state_header_ministry_fr', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_sections', function (Blueprint $table): void {
            $table->dropColumn([
                'document_language',
                'state_header_enabled',
                'state_header_ministry_en',
                'state_header_ministry_fr',
            ]);
        });

        Schema::dropIfExists('school_document_profiles');
    }
};
