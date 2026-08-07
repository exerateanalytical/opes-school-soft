<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restore_drills', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('backup_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('assertions_passed')->default(0);
            $table->text('failure_detail')->nullable();
            $table->timestamps();

            // A drill result outlives the backup it exercised: losing the history
            // would hide a pattern of failures.
            //
            // The spec drafted this RESTRICT. It is nullOnDelete instead, because
            // RESTRICT and GFS pruning are mutually exclusive: PruneBackups
            // (Task 4) deletes expired `backups` rows, and any backup that a
            // drill happened to exercise would refuse to delete - so the pruner
            // would silently stall on exactly the backups the school trusts most,
            // and the disk it was meant to protect would fill anyway. Nulling the
            // reference keeps the whole drill record - status, timing, assertion
            // count, failure detail - while letting the parent row go. The
            // drill history, which is what the health check actually reads,
            // survives; only the pointer to a file that no longer exists is lost.
            $table->foreign('backup_id')->references('id')->on('backups')->nullOnDelete();
            $table->index(['status', 'completed_at'], 'drill_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_drills');
    }
};
