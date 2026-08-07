<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('path', 500);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();

            // The ledger fingerprint the restore drill re-asserts. Recording it at
            // dump time is what lets the drill prove the restored copy is arithmetically
            // the same database, not merely a file that loads (08-operations 3.6).
            $table->json('manifest')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('failure_detail')->nullable();
            $table->string('second_target_path', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at'], 'backup_status_idx');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
