<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/01-assessment.md 13.1 - `ReportCardConfig`, immutably versioned.
 *
 * Two tables in one migration because they are not separable: a config with no
 * version cannot be rendered against, and `ReportCardSnapshot`
 * (2026_08_07_220011) points at the VERSION, never at the config head. Splitting
 * them across files would let a deployment stop between the two with a config
 * table that no snapshot can legally reference.
 *
 * Why the version table exists at all (13.1, verbatim): v1's reprint fidelity
 * guarantee was FALSE, because only numbers were snapshotted while layout,
 * labels, branding and the enabled-block set lived in a mutable config.
 * Reprinting a January bulletin in June produced January's numbers in June's
 * layout under a logo the school had since changed. T13 (19) makes that a
 * blocking test: mutate the config after publication and the re-rendered hash
 * must not move. That is only achievable if the snapshot pins a frozen version
 * row, which is what `frozen_at` plus the BEFORE UPDATE trigger below enforce
 * in the database rather than in the Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_configs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Plain nullable column, not a foreign key. `assessment_frameworks`
            // (01-assessment 3.1) is created by a sibling migration in this same
            // phase and file ordering between concurrently authored migrations
            // is not something this file may assume. The FK is added below only
            // when the table is already present, which is the same
            // phase-ordering accommodation class_groups.room_id documents.
            $table->unsignedBigInteger('framework_id')->nullable();

            // Identifier collation per 00-core 4: `BULLETIN_TRIM` and
            // `bulletin_trim` are different codes, and a case-insensitive
            // collation would silently merge them.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 160);
            $table->string('name_fr', 160);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['framework_id', 'code'], 'uq_report_card_configs_framework_code');
        });

        Schema::create('report_card_config_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT, not cascade: 00-core 10.5 forbids cascading into
            // anything a document was issued from. A config whose versions are
            // referenced by snapshots is archived (is_active = 0), never
            // deleted.
            $table->foreignId('config_id')
                ->constrained('report_card_configs')
                ->restrictOnDelete();

            $table->unsignedInteger('version_no');

            // The whole rendered shape: enabled blocks, labels, branding and
            // `marks_columns` (13.5). Stored as one document rather than
            // normalised columns because the configurator's shape is versioned
            // as a unit - a partially migrated layout is not a layout.
            $table->json('payload');

            // SHA-256 of the recursively key-sorted decode of `payload`, never
            // of the stored bytes: MySQL renormalises json columns on write
            // (00-core 14), so a byte hash does not survive a round trip.
            $table->char('payload_hash', 64);

            // NULL while the version is still editable in place; set the moment
            // a snapshot references it, after which the trigger below rejects
            // every further write.
            $table->dateTime('frozen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['config_id', 'version_no'], 'uq_report_card_config_versions');
            $table->index('frozen_at', 'idx_report_card_config_versions_frozen');
        });

        if (Schema::hasTable('assessment_frameworks')) {
            DB::statement(
                'ALTER TABLE report_card_configs '
                .'ADD CONSTRAINT fk_report_card_configs_framework '
                .'FOREIGN KEY (framework_id) REFERENCES assessment_frameworks(id)'
            );
        }

        // 13.1: "A BEFORE UPDATE trigger rejects writes to a frozen version."
        //
        // The one transition it must still permit is NULL -> NOT NULL on
        // `frozen_at` itself, because freezing is an UPDATE. Guarding on
        // OLD.frozen_at (not NEW) gives exactly that: a version can be frozen
        // once and is inert afterwards.
        //
        // This lives in the database rather than in ConfigureReportCard because
        // the guarantee it backs is a reprint-fidelity guarantee about rows that
        // may be edited years later by code nobody has written yet, including a
        // console command or a support engineer's UPDATE.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_report_card_config_versions_frozen
            BEFORE UPDATE ON report_card_config_versions
            FOR EACH ROW
            BEGIN
                IF OLD.frozen_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'This report card config version is frozen: a version referenced by an issued snapshot is immutable (01-assessment 13.1). Create a new version instead.';
                END IF;
            END
        SQL);

        // Deleting a frozen version would achieve by DELETE what the trigger
        // forbids by UPDATE. The FK from report_card_snapshots is RESTRICT and
        // covers referenced versions, but a version frozen in the same
        // transaction as a publication that then partially failed would be
        // unreferenced and deletable, so state the rule directly.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_report_card_config_versions_no_delete
            BEFORE DELETE ON report_card_config_versions
            FOR EACH ROW
            BEGIN
                IF OLD.frozen_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A frozen report card config version cannot be deleted (01-assessment 13.1).';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_report_card_config_versions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_report_card_config_versions_frozen');
        Schema::dropIfExists('report_card_config_versions');
        Schema::dropIfExists('report_card_configs');
    }
};
