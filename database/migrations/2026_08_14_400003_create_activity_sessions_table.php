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
        Schema::create('activity_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('activity_id')->constrained('activities')->restrictOnDelete();

            $table->date('scheduled_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->string('venue', 150)->nullable();

            // The staff supervisor. FK at the schema layer; the module
            // reads the row via DB::table('staff_members') only.
            $table->foreignId('supervisor_id')->nullable()->constrained('staff_members')->restrictOnDelete();

            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['activity_id', 'scheduled_on'], 'idx_activity_sessions_activity_date');
        });

        DB::statement(
            'ALTER TABLE activity_sessions ADD CONSTRAINT chk_activity_sessions_time_order '
            .'CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at >= starts_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_sessions');
    }
};
