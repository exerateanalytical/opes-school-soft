<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §14.1 - `StatutoryBook`.
 *
 * The four books of AUDCIF Art. 19: livre-journal, grand livre, balance
 * generale, livre d'inventaire. v1 omitted the livre d'inventaire entirely
 * and treated the other three as reports. They are not reports. They are
 * legal registers - signed, paginated, immutable once written, and never
 * regenerated in place.
 *
 * `supersedes_book_id` is the whole design. A book generated before a
 * correction is neither deleted nor overwritten: the regenerated book points
 * BACK at it, so the sequence of versions is itself auditable. RESTRICT on
 * that FK means a superseded row can never be pulled out from under its
 * successor, and the self-reference CHECK stops a row claiming to supersede
 * itself.
 *
 * Retention (§15, AUDCIF Art. 24 - ten years) is why there is no SoftDeletes
 * and no cascade anywhere in this table: a soft-deleted row is an invisible
 * row, and invisibility is precisely what the rule exists to prevent.
 *
 * Money is BIGINT minor units FCFA and SIGNED (00-core §5) - a book covering
 * a period of net credit movement has a legitimately negative total, and an
 * unsigned column would turn that into a write error rather than a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_books', function (Blueprint $table): void {
            $table->id();

            $table->string('book_type', 24);
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();

            $table->integer('page_count')->default(0);
            $table->string('first_piece_no', 40)->nullable();
            $table->string('last_piece_no', 40)->nullable();

            $table->bigInteger('total_debit')->default(0);
            $table->bigInteger('total_credit')->default(0);
            $table->integer('entry_count')->default(0);
            $table->integer('line_count')->default(0);

            $table->string('file_path', 500);
            $table->char('sha256', 64);

            // Detached signature over the hash, using the instance key
            // (00-core §13.5). Nullable because the key mechanism is a
            // separate deliverable; the hash above is real regardless.
            $table->text('signature')->nullable();

            $table->foreignId('supersedes_book_id')->nullable()
                ->constrained('statutory_books')->restrictOnDelete();

            $table->boolean('is_definitive')->default(false);

            $table->timestamps();

            $table->unique(
                ['book_type', 'fiscal_year_id', 'period_start', 'period_end', 'generated_at'],
                'uq_statutory_books_generation'
            );

            $table->index(['fiscal_year_id', 'book_type'], 'ix_statutory_books_year_type');
        });

        DB::statement(
            'ALTER TABLE statutory_books ADD CONSTRAINT ck_statutory_books_type '
            ."CHECK (book_type IN ('livre_journal','grand_livre','balance_generale','livre_inventaire'))"
        );

        DB::statement(
            'ALTER TABLE statutory_books ADD CONSTRAINT ck_statutory_books_period '
            .'CHECK (period_end >= period_start)'
        );

        // No CHECK forbidding self-supersession: MySQL error 3818 refuses a
        // CHECK that refers to an auto-increment column. It would be
        // redundant anyway - `supersedes_book_id` is set at INSERT, when the
        // new row's own id does not yet exist, so a row cannot name itself.
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_books');
    }
};
