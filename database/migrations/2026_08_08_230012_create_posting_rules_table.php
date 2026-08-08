<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §11.1 - `PostingRule` header. A rule is a
 * versioned, immutable-once-locked configuration row: once `is_locked`,
 * edits create `version + 1` and close the predecessor's `effective_to`
 * (exclusive), never mutate. `UNIQUE(code, version)` is the version key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier columns are accent- and case-sensitive.
            $table->string('code', 60)->collation('utf8mb4_0900_as_cs');
            $table->unsignedInteger('version')->default(1);

            $table->string('event', 80);

            $table->foreignId('journal_id')
                ->constrained('journals')->restrictOnDelete();

            $table->string('label_expression', 255);
            $table->text('condition_expression')->nullable();

            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(false);

            // Set true the first time the rule posts an entry; from then on
            // the row is immutable and edits spawn a new version.
            $table->boolean('is_locked')->default(false);

            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // exclusive

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['code', 'version'], 'uq_rule_version');
            $table->index(['event', 'is_active', 'priority'], 'ix_rule_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
    }
};
