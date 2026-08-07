<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single-row anchor recording the expected head of the audit chain.
     *
     * Without it, deleting the MOST RECENT entries is undetectable: the
     * remaining rows still form a valid chain from the genesis hash, so
     * verification reports "intact". That is the attack that matters, because
     * the newest entries are exactly the ones recording an intruder's actions.
     *
     * The anchor does not make the log tamper-PROOF - someone with database
     * access can update it too. It makes truncation tamper-EVIDENT, forcing an
     * attacker to alter two places consistently, and it gives the backup job
     * (08-operations) a small value to export off-box for external comparison.
     */
    public function up(): void
    {
        Schema::create('audit_chain_anchors', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary()->default(1);
            $table->char('last_row_hash', 64);
            $table->unsignedBigInteger('entry_count');
            $table->unsignedBigInteger('last_entry_id');
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_anchors');
    }
};
