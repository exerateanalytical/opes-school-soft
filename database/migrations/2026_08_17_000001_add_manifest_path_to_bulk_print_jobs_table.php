<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md §18.2 - the merge half of bulk printing.
 *
 * 2026_08_09_310005's own comment on `output_path` already said what it was
 * meant to hold: "The one merged PDF; per-subject files live beside it where
 * requested." ProcessBulkPrint never merged anything, so that column was
 * repurposed to hold a JSON index of the per-subject files instead.
 *
 * Now that the merge is implemented, `output_path` goes back to meaning what
 * the original migration said: the one merged PDF. The JSON index moves to
 * this new `manifest_path` column so the existing "Documents" list on the
 * Bulk Prints screen (which reads the per-subject index) keeps working
 * without reinterpreting what it finds at `output_path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_print_jobs', function (Blueprint $table): void {
            $table->string('manifest_path', 255)->nullable()->after('output_path');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_print_jobs', function (Blueprint $table): void {
            $table->dropColumn('manifest_path');
        });
    }
};
