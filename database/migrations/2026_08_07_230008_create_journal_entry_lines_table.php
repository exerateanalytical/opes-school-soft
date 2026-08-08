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
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 02-accounting 4.2: RESTRICT, never CASCADE (00-core 10.5). Lines
            // of a draft are removed by the Action explicitly, in the same
            // transaction, before the parent.
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();

            $table->smallInteger('sequence');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('label', 255);

            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);

            $table->enum('partner_type', ['student', 'guardian', 'supplier', 'staff', 'organisation'])->nullable();
            // Polymorphic across five tables (02-accounting 4.2) - deliberately
            // NOT an FK. Integrity is Action-level plus a nightly orphan job,
            // owned outside this phase's scope.
            $table->unsignedBigInteger('partner_id')->nullable();

            // Lettering (D4) and reconciliation matches are not owned by this
            // migration; no FK constraint against tables this phase does not
            // control the creation order of. Columns retained per spec.
            $table->unsignedBigInteger('lettering_id')->nullable();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->bigInteger('tax_base_amount')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('reconciliation_match_id')->nullable();

            $table->string('source_line_type', 80)->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();

            $table->timestamps();

            $table->unique(['journal_entry_id', 'sequence'], 'uq_jel_seq');
            $table->index(['account_id', 'journal_entry_id'], 'ix_jel_account_entry');
            $table->index(['partner_type', 'partner_id', 'account_id'], 'ix_jel_partner');
            $table->index('lettering_id', 'ix_jel_lettering');
            $table->index(['account_id', 'due_date'], 'ix_jel_due');
        });

        // 02-accounting 4.2 - L1. MySQL 8.0.16+ enforces CHECK constraints.
        DB::statement(
            'ALTER TABLE journal_entry_lines ADD CONSTRAINT ck_jel_one_side '
            .'CHECK ((debit = 0) <> (credit = 0))'
        );
        DB::statement(
            'ALTER TABLE journal_entry_lines ADD CONSTRAINT ck_jel_nonneg '
            .'CHECK (debit >= 0 AND credit >= 0)'
        );

        // ---------------------------------------------------------------
        // L3 - no line may be inserted, updated or deleted once the parent
        // entry is posted or reversed. Primary mechanism per 02-accounting
        // 4.3: BEFORE INSERT/UPDATE/DELETE triggers raising SIGNAL, because
        // posting rules and imports are separate write paths from
        // AddJournalEntryLine and an Action-only guard would not cover them.
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_lock_before_insert
            BEFORE INSERT ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(20);

                SELECT status INTO parent_status FROM journal_entries WHERE id = NEW.journal_entry_id;

                IF parent_status IN ('posted', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L3: cannot insert a line into a posted or reversed journal entry';
                END IF;
            END
        SQL);

        // Lettering (§10) and bank reconciliation (§13) both attach to a
        // line AFTER its entry is posted - that is the entire point of
        // either operation, and LT-5 explicitly refuses to letter a line on
        // a still-draft entry. A blanket "no update once posted" would make
        // the one write those two features need impossible, so the trigger
        // allows lettering_id/reconciliation_match_id to change on a
        // posted/reversed line while still refusing any change to a
        // financial or identifying column - debit, credit, account_id and
        // the rest stay exactly as immutable as L3 requires.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_lock_before_update
            BEFORE UPDATE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(20);

                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;

                IF parent_status IN ('posted', 'reversed') THEN
                    IF NOT (
                        OLD.journal_entry_id <=> NEW.journal_entry_id AND
                        OLD.sequence <=> NEW.sequence AND
                        OLD.account_id <=> NEW.account_id AND
                        OLD.label <=> NEW.label AND
                        OLD.debit <=> NEW.debit AND
                        OLD.credit <=> NEW.credit AND
                        OLD.partner_type <=> NEW.partner_type AND
                        OLD.partner_id <=> NEW.partner_id AND
                        OLD.tax_code_id <=> NEW.tax_code_id AND
                        OLD.tax_base_amount <=> NEW.tax_base_amount AND
                        OLD.due_date <=> NEW.due_date AND
                        OLD.source_line_type <=> NEW.source_line_type AND
                        OLD.source_line_id <=> NEW.source_line_id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L3: cannot update a line on a posted or reversed journal entry';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_lock_before_delete
            BEFORE DELETE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(20);

                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;

                IF parent_status IN ('posted', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L3: cannot delete a line from a posted or reversed journal entry';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_lock_before_insert');
        Schema::dropIfExists('journal_entry_lines');
    }
};
