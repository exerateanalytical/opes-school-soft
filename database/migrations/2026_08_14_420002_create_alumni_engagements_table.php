<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The interaction log behind an AlumnusRecord: each row is one touch point
 * (a donation, a visit, a careers talk, a mentorship, anything else) on a
 * date, with a note. Append-only from the UI - RecordEngagement creates,
 * nothing edits or deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumni_engagements', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('alumnus_record_id')
                ->constrained('alumnus_records')->restrictOnDelete();

            $table->enum('type', ['donation', 'visit', 'talk', 'mentorship', 'other']);

            $table->date('engaged_on');
            $table->string('note', 500);

            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The detail screen's timeline and the "engagements this year"
            // KPI both walk this.
            $table->index(['alumnus_record_id', 'engaged_on'], 'ix_alumni_engagements_record_date');
            $table->index('engaged_on', 'ix_alumni_engagements_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumni_engagements');
    }
};
