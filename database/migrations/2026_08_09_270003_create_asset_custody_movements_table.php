<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §2.3 - asset_custody_movements.
 * Append-only: BEFORE UPDATE permits ONLY the acknowledgement transition
 * (NULL -> a value, everything else byte-identical); BEFORE DELETE always
 * rejects. Corrections are new movements. No accounting effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_custody_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            $table->foreignId('from_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('to_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();

            // Polymorphic across rooms / store_locations (F3): no FK by
            // design, mirrors assets.location_id.
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();

            $table->date('moved_on');
            $table->string('reason', 255);
            $table->string('document_ref', 80)->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->foreignId('recorded_by')
                ->constrained('users')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()->unique('uq_custody_idem');

            $table->timestamps();

            $table->index(['asset_id', 'moved_on'], 'ix_custody_asset_moved');
        });

        // A movement must go SOMEWHERE: a new custodian, a new location, or
        // both.
        DB::statement(
            'ALTER TABLE asset_custody_movements ADD CONSTRAINT chk_custody_destination CHECK ( '
            .'to_staff_id IS NOT NULL OR to_location_id IS NOT NULL )'
        );

        // Append-only (§2.3): the ONLY legal update is acknowledging an
        // unacknowledged movement, with every substantive column untouched.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_custody_append_only_before_update
            BEFORE UPDATE ON asset_custody_movements
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.asset_id <=> NEW.asset_id
                    AND OLD.from_staff_id <=> NEW.from_staff_id
                    AND OLD.to_staff_id <=> NEW.to_staff_id
                    AND OLD.from_location_id <=> NEW.from_location_id
                    AND OLD.to_location_id <=> NEW.to_location_id
                    AND OLD.moved_on <=> NEW.moved_on
                    AND OLD.reason <=> NEW.reason
                    AND OLD.document_ref <=> NEW.document_ref
                    AND OLD.recorded_by <=> NEW.recorded_by
                    AND OLD.idempotency_key <=> NEW.idempotency_key
                    AND OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_custody_movements is append-only: corrections are new movements';
                END IF;

                IF OLD.acknowledged_at IS NOT NULL
                    AND NOT (OLD.acknowledged_at <=> NEW.acknowledged_at
                        AND OLD.acknowledged_by <=> NEW.acknowledged_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_custody_movements: an acknowledgement is immutable once recorded';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_custody_append_only_before_delete
            BEFORE DELETE ON asset_custody_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_custody_movements is append-only: rows are never deleted';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_custody_append_only_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_custody_append_only_before_delete');
        Schema::dropIfExists('asset_custody_movements');
    }
};
