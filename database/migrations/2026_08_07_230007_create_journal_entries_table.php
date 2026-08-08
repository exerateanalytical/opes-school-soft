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
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 02-accounting 3: journals are entities, never a bare string.
            $table->foreignId('journal_id')->constrained('journals')->restrictOnDelete();

            // 02-accounting 4.1 / 00-core 12: gapless, allocated ONLY at
            // posting by SequenceAllocator inside the posting transaction.
            // NULL while status = draft.
            $table->string('piece_no', 40)->nullable()->collation('utf8mb4_0900_as_cs');

            // Accounting date (where the entry sits in the books) vs economic
            // date of the operation - 02-accounting 4.1 / 5.4.
            $table->date('date');
            $table->date('value_date');

            // 02-accounting L6: derived from `date` in PostJournalEntry,
            // NEVER supplied by the caller. Backstopped by a BEFORE
            // INSERT/UPDATE trigger below (C3) that re-derives and compares.
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->string('label', 255);
            $table->string('reference', 80)->nullable();

            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');

            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // PostingRule (02-accounting 11) is not owned by this migration -
            // its table is built by whichever phase-4 agent lands the posting
            // rules engine. No FK constraint is added here to avoid an
            // ordering dependency on a table this migration does not control;
            // the column is retained, per spec, as a plain nullable reference
            // so PostJournalEntry can stamp it once that engine exists.
            $table->unsignedBigInteger('posting_rule_id')->nullable();
            $table->unsignedInteger('posting_rule_version')->nullable();

            // 02-accounting 4.1/9/12: reversal is sibling D4's Action, but the
            // schema (both directions, both UNIQUE, the generated column,
            // the "no double reversal" CHECK) belongs to this table.
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->unsignedBigInteger('reversed_by_entry_id')->nullable();
            $table->boolean('is_reversal')
                ->storedAs('CASE WHEN `reverses_entry_id` IS NOT NULL THEN 1 ELSE 0 END');
            $table->string('reversal_reason', 500)->nullable();

            $table->boolean('is_migration')->default(false);
            $table->boolean('is_forward_posted')->default(false);

            // Denormalised, maintained by PostJournalEntry / DraftJournalEntry
            // under Money arithmetic; asserted equal by L2's Action-level
            // check AND the ck_je_totals CHECK below.
            $table->bigInteger('total_debit')->default(0);
            $table->bigInteger('total_credit')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->string('idempotency_key', 120)->nullable();
            $table->unsignedInteger('attachment_count')->default(0);

            $table->timestamps();

            $table->unique(['journal_id', 'fiscal_year_id', 'piece_no'], 'uq_je_piece');
            $table->unique(['source_type', 'source_id', 'posting_rule_id'], 'uq_je_source_rule');
            $table->unique('reverses_entry_id', 'uq_je_reverses');
            $table->unique('reversed_by_entry_id', 'uq_je_reversed_by');
            $table->unique('idempotency_key', 'uq_je_idem');

            $table->index(['accounting_period_id', 'status'], 'ix_je_period');
            $table->index('date', 'ix_je_date');
            $table->index('value_date', 'ix_je_value_date');
            $table->index(['source_type', 'source_id'], 'ix_je_source');

            $table->foreign('reverses_entry_id', 'fk_je_reverses')
                ->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('reversed_by_entry_id', 'fk_je_reversed_by')
                ->references('id')->on('journal_entries')->restrictOnDelete();
        });

        // 02-accounting 4.1, keys block, verbatim.
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT ck_je_reversal_reason '
            .'CHECK (reverses_entry_id IS NULL OR reversal_reason IS NOT NULL)'
        );
        // ck_je_no_self_reverse cannot be a CHECK constraint: MySQL 8 refuses
        // (error 3818) a CHECK that references an AUTO_INCREMENT column
        // (`id`). A BEFORE INSERT/UPDATE trigger is the equivalent guard -
        // added further down alongside the other journal_entries triggers.
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT ck_je_piece_when_posted '
            ."CHECK (status = 'draft' OR piece_no IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT ck_je_totals '
            .'CHECK (total_debit = total_credit)'
        );

        // ---------------------------------------------------------------
        // ck_je_no_self_reverse backstop (see comment above): an entry may
        // not name itself as the one it reverses. Split insert/update
        // because `id` does not exist yet at BEFORE INSERT time for a new
        // auto-increment row - NEW.id reads as NULL there, so the insert
        // side only needs to guard the case where a caller explicitly sets
        // reverses_entry_id to a value that will collide once the ID is
        // assigned, which is structurally impossible before the row exists;
        // the real risk is entirely on UPDATE, guarded below.
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_je_no_self_reverse_before_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF NEW.reverses_entry_id IS NOT NULL AND NEW.reverses_entry_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ck_je_no_self_reverse: an entry cannot reverse itself';
                END IF;
            END
        SQL);

        // ---------------------------------------------------------------
        // L4 - a posted (or reversed) entry's identity/date columns are
        // immutable. Primary mechanism per 02-accounting 4.3: a BEFORE
        // UPDATE trigger, not an Action-only check, because posting rules,
        // imports and manual correction Actions are separate write paths.
        // Applies once the entry has left `draft` (posted or reversed) -
        // matching L3's parent-status test on the lines table.
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_je_immutable_before_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    IF NOT (OLD.date <=> NEW.date) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: date is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.value_date <=> NEW.value_date) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: value_date is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.journal_id <=> NEW.journal_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: journal_id is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.piece_no <=> NEW.piece_no) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: piece_no is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.accounting_period_id <=> NEW.accounting_period_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: accounting_period_id is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.fiscal_year_id <=> NEW.fiscal_year_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: fiscal_year_id is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.academic_year_id <=> NEW.academic_year_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: academic_year_id is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.posting_rule_id <=> NEW.posting_rule_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: posting_rule_id is immutable once the entry is posted or reversed';
                    END IF;
                    IF NOT (OLD.posting_rule_version <=> NEW.posting_rule_version) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L4: posting_rule_version is immutable once the entry is posted or reversed';
                    END IF;
                END IF;
            END
        SQL);

        // ---------------------------------------------------------------
        // L6 backstop (C3) - accounting_period_id / fiscal_year_id /
        // academic_year_id must always be exactly what `date` derives to.
        // The primary mechanism is that PostJournalEntry's DTO has no such
        // parameters at all; this trigger is the database-level proof that
        // no other write path (import, direct SQL, a future bug) can smuggle
        // a mismatched value in. Runs on INSERT and on UPDATE (only while
        // still draft - L4 already froze these columns once posted).
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_je_derive_calendar_before_insert
            BEFORE INSERT ON journal_entries
            FOR EACH ROW
            BEGIN
                DECLARE expected_period_id BIGINT;
                DECLARE expected_fy_id BIGINT;
                DECLARE expected_ay_id BIGINT;

                SELECT id INTO expected_period_id FROM accounting_periods
                    WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;
                SELECT id INTO expected_fy_id FROM fiscal_years
                    WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;
                SELECT id INTO expected_ay_id FROM academic_years
                    WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;

                IF expected_period_id IS NULL OR NEW.accounting_period_id <> expected_period_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: accounting_period_id does not match the period derived from date';
                END IF;
                IF expected_fy_id IS NULL OR NEW.fiscal_year_id <> expected_fy_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: fiscal_year_id does not match the fiscal year derived from date';
                END IF;
                IF expected_ay_id IS NULL OR NEW.academic_year_id <> expected_ay_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: academic_year_id does not match the academic year derived from date';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_je_derive_calendar_before_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                DECLARE expected_period_id BIGINT;
                DECLARE expected_fy_id BIGINT;
                DECLARE expected_ay_id BIGINT;

                IF OLD.status = 'draft' THEN
                    SELECT id INTO expected_period_id FROM accounting_periods
                        WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;
                    SELECT id INTO expected_fy_id FROM fiscal_years
                        WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;
                    SELECT id INTO expected_ay_id FROM academic_years
                        WHERE starts_on <= NEW.date AND ends_on >= NEW.date LIMIT 1;

                    IF expected_period_id IS NULL OR NEW.accounting_period_id <> expected_period_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: accounting_period_id does not match the period derived from date';
                    END IF;
                    IF expected_fy_id IS NULL OR NEW.fiscal_year_id <> expected_fy_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: fiscal_year_id does not match the fiscal year derived from date';
                    END IF;
                    IF expected_ay_id IS NULL OR NEW.academic_year_id <> expected_ay_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L6/C3: academic_year_id does not match the academic year derived from date';
                    END IF;
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_je_derive_calendar_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_je_derive_calendar_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_je_immutable_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_je_no_self_reverse_before_update');
        Schema::dropIfExists('journal_entries');
    }
};
