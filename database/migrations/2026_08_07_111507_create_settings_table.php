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
        Schema::create('settings', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // as_cs per 00-core 4: identifier columns are case- and
            // accent-sensitive, so Academic.PassMark and academic.pass_mark
            // cannot collide into one another's value.
            $table->string('key', 120)->collation('utf8mb4_0900_as_cs');
            $table->json('value')->nullable();
            $table->json('default_value')->nullable();
            $table->string('value_type', 20);
            $table->string('setting_class', 30);
            $table->string('scope', 30)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('validation_rule', 255)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Sentinel 0 rather than NULL: MySQL UNIQUE ignores NULLs, so a
            // nullable scope_id would permit unlimited duplicate global rows
            // for one key - the same trap 04-fees documents on FeeStructure.
            $table->unsignedBigInteger('scope_key')->storedAs('COALESCE(scope_id, 0)');
            $table->unique(['key', 'scope', 'scope_key'], 'settings_key_scope_unique');

            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
