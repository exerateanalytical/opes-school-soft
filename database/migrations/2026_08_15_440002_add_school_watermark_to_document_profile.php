<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SCHOOL's own watermark - additive to, never replacing, the derived
 * status watermarks (DUPLICATA / ANNULE / SPECIMEN).
 *
 * Why a second, independent layer rather than a fourth value in the existing
 * one: the status watermark says what STATE this copy is in, the school
 * watermark says WHOSE document it is. Folding them together means the first
 * reprint of any document silently drops the school's mark - exactly when the
 * document is most likely to be scrutinised.
 *
 * Both stay OUT of the hashed artefact, like the status watermark already is
 * (RenderDocument::issueOriginal hashes the CLEAN render and applies overlays
 * to a separate one). That is what lets a school switch this on without
 * retroactively breaking the reproducibility of every document it has already
 * issued.
 *
 * Defaults are OFF and empty on purpose: a live school must print exactly
 * what it printed yesterday until someone deliberately changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->boolean('watermark_enabled')->default(false)->after('school_stamp_path');
            $table->string('watermark_text', 60)->nullable()->after('watermark_enabled');
            $table->string('watermark_image_path', 255)->nullable()->after('watermark_text');
            // Percent, 1-30. Stored as an integer because it is a setting a
            // human types, and 0.08 in a text box is how a school ends up
            // with an invisible or an opaque watermark. Above 30 the mark
            // competes with the text it sits behind.
            $table->unsignedTinyInteger('watermark_opacity')->default(8)->after('watermark_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'watermark_enabled', 'watermark_text', 'watermark_image_path', 'watermark_opacity',
            ]);
        });
    }
};
