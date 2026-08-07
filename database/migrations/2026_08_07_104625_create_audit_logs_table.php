<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('action', 40);
            $table->string('module', 60);
            $table->string('auditable_type', 191)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name_at_time', 191);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            // 00-core 14: indexed for the real question, "who changed this record".
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_subject_idx');
            $table->index(['actor_id', 'created_at'], 'audit_actor_idx');
            $table->index(['module', 'created_at'], 'audit_module_idx');
            $table->unique('row_hash');

            // RESTRICT, never cascade: 00-core 10.5. Users are deactivated, not deleted,
            // so an audit entry can never be orphaned or silently removed with its actor.
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
