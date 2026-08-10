<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §17.3 - `YearEndChecklist` and
 * `YearEndChecklistItem`, the two tables that make §17.2's thirteen-step
 * close sequence a record rather than a memory.
 *
 * Until now `FiscalYearStatus::Closing/Closed` existed with nothing able to
 * reach them, and `PostingEvent::YearEndClosing/YearEndAppropriation/
 * YearEndOpeningBalances` were enum cases no Action emitted: the ledger
 * could not roll into a second exercice at all. These tables are the state
 * the close sequence runs against.
 *
 * The invariants of §17.3 land where they can actually be enforced:
 *
 *  - YE-1 (no `closed` while a mandatory item is `pending`) is in-Action,
 *    under `FOR UPDATE` on the checklist row - it spans two tables and a
 *    third (`fiscal_years`), so a CHECK cannot express it.
 *  - YE-2 (a waiver needs a reason) is BOTH: `ck_yeci_waiver_reason` here,
 *    and the >= 20 character rule plus the permission gate in
 *    WaiveYearEndChecklistItem. The CHECK is the backstop against any other
 *    writer; the length and the permission are policy.
 *  - YE-3 (items complete in `sequence` order) and YE-4 (an automated item
 *    self-completes only on a clean validation) are in-Action - both are
 *    statements about OTHER rows, which is exactly what a CHECK cannot see.
 *
 * `validation_result` is JSON on purpose: the §17.9 validation returns a
 * pass/fail set with the offending row ids, and that set IS the evidence an
 * auditor asks for. Storing the shape rather than a boolean is the whole
 * point of the column.
 *
 * Deletes are RESTRICT everywhere (00-core §9). A close that happened is a
 * financial record; a fiscal year is never deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_end_checklists', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // §17.3: UNIQUE. One checklist per exercice, ever - a second
            // close attempt resumes the first one's row rather than opening
            // a parallel, contradictory record of what was signed off.
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete()
                ->unique('uq_year_end_checklists_fy');

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('not_started');

            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('status', 'ix_year_end_checklists_status');
        });

        Schema::create('year_end_checklist_items', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('year_end_checklist_id')
                ->constrained('year_end_checklists')->restrictOnDelete();

            // §17.2's step number. The ordering is the sequence, and YE-3
            // reads it: a later item cannot complete before an earlier
            // mandatory one.
            $table->unsignedSmallInteger('sequence');

            // 00-core §4: identifiers are accent- and case-sensitive.
            $table->string('code', 60)->collation('utf8mb4_0900_as_cs');

            $table->string('title', 200);
            $table->string('title_fr', 200);

            $table->boolean('is_mandatory')->default(true);

            // §17.3 YE-4: an automated item is one whose validation Action
            // decides its own status. A human may not tick it; they may only
            // waive it, and the waiver is printed on the closing report.
            $table->boolean('is_automated')->default(false);

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('pending');

            $table->foreignId('completed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();

            // §17.8 maker-checker: the user who RAN the step is recorded
            // separately from the one who signed it off, because the whole
            // control is that they differ.
            $table->foreignId('performed_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->string('waiver_reason', 500)->nullable();
            $table->foreignId('waived_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('waived_at')->nullable();

            // What satisfied the item: the JournalEntry the step posted, the
            // ResultAppropriation approved, the report generated.
            $table->string('evidence_type', 120)->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();

            // The §17.9 pass/fail set, verbatim, with the offending row ids.
            $table->json('validation_result')->nullable();

            $table->timestamps();

            $table->unique(['year_end_checklist_id', 'code'], 'uq_yeci_code');
            $table->unique(['year_end_checklist_id', 'sequence'], 'uq_yeci_sequence');
            $table->index('status', 'ix_yeci_status');
        });

        foreach (self::CHECKLIST_CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `year_end_checklists` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }

        foreach (self::ITEM_CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `year_end_checklist_items` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_checklist_items');
        Schema::dropIfExists('year_end_checklists');
    }

    /**
     * @var array<string, string>
     */
    private const CHECKLIST_CHECKS = [
        'ck_year_end_checklists_status' => "`status` IN ('not_started','in_progress','completed')",

        // A completed checklist is a complete record of who completed it.
        'ck_year_end_checklists_completed' => "`status` <> 'completed' OR (`completed_at` IS NOT NULL AND `completed_by` IS NOT NULL)",
    ];

    /**
     * @var array<string, string>
     */
    private const ITEM_CHECKS = [
        'ck_yeci_status' => "`status` IN ('pending','completed','waived')",

        // §17.3 YE-2, the backstop half.
        'ck_yeci_waiver_reason' => "`status` <> 'waived' OR (`waiver_reason` IS NOT NULL AND `waiver_reason` <> '' AND `waived_by` IS NOT NULL)",

        // A pending item carries no sign-off and no waiver: nothing that
        // reads "done" may survive a re-open that set it back to pending.
        'ck_yeci_pending_clean' => "`status` <> 'pending' OR (`completed_at` IS NULL AND `completed_by` IS NULL AND `waiver_reason` IS NULL)",

        // A completed item names its signatory - unless it is an AUTOMATED
        // item (YE-4), which completes itself off a clean validation and may
        // legitimately have been run unattended, by the scheduler, with no
        // user at all. It still carries `completed_at` and its
        // `validation_result` evidence.
        'ck_yeci_completed' => "`status` <> 'completed' OR (`completed_at` IS NOT NULL AND (`completed_by` IS NOT NULL OR `is_automated` = 1))",

        // Evidence is a (type, id) pair or neither half.
        'ck_yeci_evidence_pair' => '(`evidence_type` IS NULL AND `evidence_id` IS NULL) OR (`evidence_type` IS NOT NULL AND `evidence_id` IS NOT NULL)',
    ];
};
