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
        Schema::create('recovery_credentials', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code_hash', 255);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('generated_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['used_at', 'revoked_at', 'expires_at'], 'recovery_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recovery_credentials');
    }
};
