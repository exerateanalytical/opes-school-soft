<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.1 - the bibliographic layer.
 *
 * `book_categories` is a managed taxonomy (the mockup's right rail counts
 * COPIES per category, derived - never a stored counter). `shelf_locations`
 * is the physical addressing scheme. `books` is the TITLE record; the
 * circulating unit is `book_copies` (270019).
 *
 * Identifier columns are utf8mb4_0900_as_cs (00-core: identifiers compare
 * case-sensitively); money is BIGINT signed FCFA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_book_categories_code');
            $table->string('name', 160);
            $table->string('name_fr', 160);

            // RESTRICT: a parent with children is reassigned, not orphaned.
            $table->foreignId('parent_id')->nullable()
                ->constrained('book_categories')->restrictOnDelete();

            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        Schema::create('shelf_locations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_shelf_locations_code');
            $table->string('name', 120);
            $table->string('section', 120)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();

            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // UNIQUE NULL: many books legitimately have no ISBN (locally
            // printed workbooks); MySQL treats NULLs as distinct here,
            // which is exactly right.
            $table->string('isbn', 20)->collation('utf8mb4_0900_as_cs')->nullable()
                ->unique('uq_books_isbn');

            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->string('author', 160);
            $table->string('co_authors', 255)->nullable();
            $table->string('publisher', 160)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('edition', 60)->nullable();
            $table->string('language', 40)->default('en');

            $table->foreignId('book_category_id')
                ->constrained('book_categories')->restrictOnDelete();

            $table->string('dewey_or_call_number', 40)->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->text('summary')->nullable();
            $table->string('cover_path', 255)->nullable();

            // §10.5: the fine cap and the loss fine both read this. FCFA.
            $table->bigInteger('replacement_cost')->default(0);

            // Dictionaries and atlases never circulate (§10.1).
            $table->boolean('is_reference_only')->default(false);
            $table->boolean('is_archived')->default(false);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['book_category_id', 'is_archived'], 'ix_books_category');
            $table->index('author', 'ix_books_author');
        });

        DB::statement(
            'ALTER TABLE books ADD CONSTRAINT chk_books_replacement_cost CHECK (replacement_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
        Schema::dropIfExists('shelf_locations');
        Schema::dropIfExists('book_categories');
    }
};
