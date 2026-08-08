<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * This filename and slot were pre-assigned to ADD `partner_type`,
 * `partner_id` and `lettering_id` to `journal_entry_lines`. Per this
 * agent's brief: "If D3's line schema already includes partner_type/
 * partner_id/lettering_id by the time you check, skip your ALTER entirely
 * ... rather than creating a duplicate/conflicting column." By the time
 * this agent checked, `database/migrations/2026_08_07_230008_create_
 * journal_entry_lines_table.php` already carried all three columns
 * verbatim against docs/specs/02-accounting.md §4.2, including
 * `partner_id` deliberately NOT as a foreign key (§8.3: polymorphic across
 * five tables, integrity by Action + nightly job). The column-add is
 * therefore skipped - no duplicate/conflicting DDL is issued here.
 *
 * Two things D3's migration explicitly could NOT do at 230008, because
 * they depend on tables this agent owns, land in this slot instead:
 *
 *  1. The FK on `lettering_id` - `Lettering` (230009) does not exist yet
 *     at 230008.
 *  2. L8's trigger - it must join `chart_of_accounts`, and needs the final
 *     shape of `journal_entry_lines`, so it is written once, here, rather
 *     than split across two agents' migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §4.2 field table, verbatim: "lettering_id | FK -> Lettering, ON
        // DELETE SET NULL (unlettering deletes the group)". Read against
        // §10.4 (the authoritative prose for what UnletterGroup actually
        // does), that one-liner is imprecise: unlettering does NOT delete
        // the Lettering row - it sets `lettering_id = NULL` on the member
        // lines and RETAINS the row as a historical record ("the row is
        // never hard-deleted", §15). See UnletterGroup's docblock for the
        // full quote. This FK is still correct as the belt for the case
        // the row is ever removed by some other path; ON DELETE SET NULL
        // is exactly what §4.2 specifies for that case.
        DB::statement(
            'ALTER TABLE journal_entry_lines '
            .'ADD CONSTRAINT fk_jel_lettering FOREIGN KEY (lettering_id) REFERENCES letterings (id) '
            .'ON DELETE SET NULL ON UPDATE RESTRICT'
        );

        // ---------------------------------------------------------------
        // L8 (02-accounting §8.3, §4.3) - stated exactly:
        //   "A JournalEntryLine whose account has is_collective = true
        //    MUST carry a non-null partner_type and partner_id, and
        //    partner_type must be in the account's allowed_partner_types.
        //    A JournalEntryLine whose account has is_collective = false
        //    MUST NOT carry a partner."
        // Primary mechanism per §8.3: a BEFORE INSERT/UPDATE trigger
        // joining chart_of_accounts, "precisely because posting rules, the
        // opening-balance import and manual entry are three different
        // write paths, and a check in one Action protects none of the
        // others."
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_l8_before_insert
            BEFORE INSERT ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_is_collective TINYINT(1);
                DECLARE v_allowed JSON;

                SELECT is_collective, allowed_partner_types INTO v_is_collective, v_allowed
                FROM chart_of_accounts WHERE id = NEW.account_id;

                IF v_is_collective = 1 AND (NEW.partner_type IS NULL OR NEW.partner_id IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: collective account requires a partner';
                END IF;

                IF v_is_collective = 0 AND NEW.partner_type IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: non-collective account must not carry a partner';
                END IF;

                IF v_is_collective = 1 AND v_allowed IS NOT NULL AND NEW.partner_type IS NOT NULL
                   AND NOT JSON_CONTAINS(v_allowed, JSON_QUOTE(NEW.partner_type)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: partner_type is not in the account allowed_partner_types';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_l8_before_update
            BEFORE UPDATE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_is_collective TINYINT(1);
                DECLARE v_allowed JSON;

                SELECT is_collective, allowed_partner_types INTO v_is_collective, v_allowed
                FROM chart_of_accounts WHERE id = NEW.account_id;

                IF v_is_collective = 1 AND (NEW.partner_type IS NULL OR NEW.partner_id IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: collective account requires a partner';
                END IF;

                IF v_is_collective = 0 AND NEW.partner_type IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: non-collective account must not carry a partner';
                END IF;

                IF v_is_collective = 1 AND v_allowed IS NOT NULL AND NEW.partner_type IS NOT NULL
                   AND NOT JSON_CONTAINS(v_allowed, JSON_QUOTE(NEW.partner_type)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: partner_type is not in the account allowed_partner_types';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_l8_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_l8_before_insert');
        DB::statement('ALTER TABLE journal_entry_lines DROP FOREIGN KEY fk_jel_lettering');
    }
};
