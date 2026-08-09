<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.9 - the turnstile table behind the
 * "Daily Visits" statistic. member NULL = a walk-in / guest visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_visits', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('library_member_id')->nullable()
                ->constrained('library_members')->restrictOnDelete();

            $table->date('visited_on');
            $table->time('visited_at_time')->nullable();
            $table->string('purpose', 160)->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('visited_on', 'ix_library_visits_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_visits');
    }
};
