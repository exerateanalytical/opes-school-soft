<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/00-core.md §15 Phase 2 - the data import suite.
 *
 * Two tables, because an import is three phases and not one: stage parses,
 * validate judges, commit writes. Keeping the parsed rows in `import_rows`
 * is what makes a dry run possible - an operator sees exactly which rows
 * would fail BEFORE a single student exists, and fixes the source file
 * rather than unpicking half an import afterwards.
 *
 * `imported_record_id` is what makes a commit RESUMABLE: a run that dies
 * halfway leaves its successful rows marked, and re-running skips them
 * instead of creating a second copy of every student. It is deliberately
 * NOT a foreign key - it is polymorphic across students/guardians/staff,
 * and deleting a record must not silently rewrite import history.
 *
 * `import_rows` cascades on batch delete, the one cascade in this design: a
 * staged row has no academic or accounting meaning of its own, and a
 * discarded upload must not leave orphans behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();

            $table->string('kind', 24);
            $table->string('original_filename', 255);
            $table->char('sha256', 64);
            $table->string('status', 16)->default('staged');

            $table->integer('row_count')->default(0);
            $table->integer('valid_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->integer('imported_count')->default(0);

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('committed_at')->nullable();

            $table->timestamps();

            $table->index(['kind', 'status'], 'ix_import_batches_kind_status');
        });

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT ck_import_batches_kind '
            ."CHECK (kind IN ('students','guardians','staff'))"
        );

        DB::statement(
            'ALTER TABLE import_batches ADD CONSTRAINT ck_import_batches_status '
            ."CHECK (status IN ('staged','validated','committed','failed'))"
        );

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->integer('row_no');

            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->json('errors')->nullable();

            $table->string('imported_record_type', 120)->nullable();
            $table->unsignedBigInteger('imported_record_id')->nullable();

            $table->timestamps();

            $table->unique(['import_batch_id', 'row_no'], 'uq_import_rows_batch_row');
            $table->index(['import_batch_id', 'status'], 'ix_import_rows_batch_status');
        });

        DB::statement(
            'ALTER TABLE import_rows ADD CONSTRAINT ck_import_rows_status '
            ."CHECK (status IN ('pending','valid','invalid','imported','skipped'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
