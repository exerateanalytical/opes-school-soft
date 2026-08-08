<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SYSCOHADA revise chart of accounts (docs/specs/02-accounting.md 2.1).
 *
 * Hierarchy invariants CoA-1, CoA-2, CoA-4 and CoA-5 (2.2) are implemented as
 * raw BEFORE INSERT/UPDATE triggers, per the spec's stated primary mechanism -
 * a check only ever exercised through the Action layer is not proven to work
 * as a backstop against a bad direct write (an import, a console command, a
 * future developer bypassing the Action). CoA-3 (no cycles) needs no separate
 * mechanism: CoA-2's prefix-and-depth rule is a strict partial order, so a
 * cycle is structurally impossible. CoA-6 (type/normal_balance consistent
 * with account_class) and CoA-7 (dsf_line_code required before year-end
 * close) are NOT triggers here - see the Actions and the handoff note below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier collation - accent/case sensitive so that
            // codes are never falsely merged or falsely rejected as
            // duplicates.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique('uq_coa_code');

            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('name', 180);
            $table->string('name_fr', 180);
            $table->string('name_en', 180)->nullable();
            $table->string('display_alias', 180)->nullable();

            $table->enum('type', [
                'asset', 'liability', 'equity', 'revenue', 'expense', 'offbalance', 'analytic',
            ]);
            $table->enum('normal_balance', ['debit', 'credit']);

            $table->boolean('is_postable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_collective')->default(false);
            $table->boolean('requires_partner')->default(false);
            $table->json('allowed_partner_types')->nullable();
            $table->boolean('requires_analytic')->default(false);
            $table->boolean('is_lettrable')->default(false);
            $table->boolean('is_reconcilable')->default(false);

            $table->string('dsf_line_code', 20)->nullable();
            $table->enum('dsf_statement', ['bilan_actif', 'bilan_passif', 'resultat', 'flux', 'note'])->nullable();

            // No `tax_codes` table exists yet (03-tax-procurement is a sibling
            // phase's work). A plain nullable unsigned BIGINT with no FK is a
            // deliberate placeholder: the sibling phase adds the constraint
            // when TaxCode lands, per 02-accounting.md 2.1.
            $table->unsignedBigInteger('default_tax_code_id')->nullable()
                ->comment('FK -> tax_codes.id (03-tax-procurement), RESTRICT. No FK yet: TaxCode does not exist on disk.');

            $table->enum('budget_control', ['none', 'warn', 'block'])->default('none');
            $table->char('currency', 3)->default('XAF');

            $table->boolean('is_archived')->default(false);
            $table->date('opened_at')->nullable();
            $table->date('archived_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('parent_id', 'ix_coa_parent');
            $table->index(['dsf_statement', 'dsf_line_code'], 'ix_coa_dsf');
        });

        // account_class and depth are STORED generated columns, added via raw
        // SQL: Laravel's Blueprint::computed() targets Laravel's own generated
        // column syntax fine for simple cases, but CAST(LEFT(code,1) AS
        // UNSIGNED) needs the exact expression the spec specifies, so it is
        // written directly.
        DB::statement(
            'ALTER TABLE chart_of_accounts '
            .'ADD COLUMN account_class TINYINT UNSIGNED AS (CAST(LEFT(code, 1) AS UNSIGNED)) STORED AFTER parent_id, '
            .'ADD COLUMN depth TINYINT UNSIGNED AS (LENGTH(code)) STORED AFTER account_class'
        );

        // The composite index needs both generated columns to already
        // exist, so it is added in its own ALTER after the columns land.
        DB::statement('ALTER TABLE chart_of_accounts ADD INDEX ix_coa_class_depth (account_class, depth)');

        DB::statement(
            'ALTER TABLE chart_of_accounts ADD CONSTRAINT ck_coa_code_numeric CHECK (code REGEXP \'^[0-9]{1,20}$\')'
        );
        DB::statement(
            'ALTER TABLE chart_of_accounts ADD CONSTRAINT ck_coa_class CHECK (account_class BETWEEN 1 AND 9)'
        );
        DB::statement(
            'ALTER TABLE chart_of_accounts ADD CONSTRAINT ck_coa_partner '
            .'CHECK (NOT (requires_partner = 1 AND is_collective = 0) OR is_collective = 1)'
        );
        DB::statement(
            'ALTER TABLE chart_of_accounts ADD CONSTRAINT ck_coa_currency CHECK (currency = \'XAF\')'
        );

        // ON DELETE RESTRICT everywhere (00-core 10.5): an account is
        // archived, never deleted, and a parent with live children must never
        // be removable out from under them.
        DB::statement(
            'ALTER TABLE chart_of_accounts '
            .'ADD CONSTRAINT fk_coa_parent FOREIGN KEY (parent_id) REFERENCES chart_of_accounts (id) '
            .'ON DELETE RESTRICT ON UPDATE RESTRICT'
        );

        $this->createTriggers();
    }

    private function createTriggers(): void
    {
        // CoA-1: parent_id IS NULL iff depth = 1. A root account (the SYSCOHADA
        // class digit, e.g. code "2") has no parent; every other account must
        // have one.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_coa_before_insert_coa1_2
            BEFORE INSERT ON chart_of_accounts
            FOR EACH ROW
            BEGIN
                DECLARE v_depth TINYINT UNSIGNED;
                DECLARE v_parent_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                DECLARE v_parent_depth TINYINT UNSIGNED;

                SET v_depth = LENGTH(NEW.code);

                IF v_depth = 1 AND NEW.parent_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'CoA-1: a depth-1 (class root) account must not have a parent_id';
                END IF;

                IF v_depth > 1 AND NEW.parent_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'CoA-1: an account with depth > 1 must have a parent_id';
                END IF;

                -- CoA-2: parent.code is a strict prefix of code, and
                -- parent.depth = depth - 1. CoA-3 (no cycles) follows for
                -- free: prefix-and-depth ordering is a strict partial order,
                -- so no chain of these edges can return to its start.
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT code, LENGTH(code) INTO v_parent_code, v_parent_depth
                    FROM chart_of_accounts WHERE id = NEW.parent_id;

                    IF v_parent_code IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent_id does not reference an existing account';
                    END IF;

                    IF v_parent_depth <> v_depth - 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent.depth must equal depth - 1';
                    END IF;

                    IF LEFT(NEW.code, v_parent_depth) <> v_parent_code OR NEW.code = v_parent_code THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent.code must be a strict prefix of code';
                    END IF;
                END IF;
            END
        SQL);

        // CoA-1/CoA-2 again on UPDATE: code, parent_id or both may change
        // (e.g. re-parenting is not exposed by any Action today, but the
        // trigger is the backstop regardless of write path).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_coa_before_update_coa1_2
            BEFORE UPDATE ON chart_of_accounts
            FOR EACH ROW
            BEGIN
                DECLARE v_depth TINYINT UNSIGNED;
                DECLARE v_parent_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                DECLARE v_parent_depth TINYINT UNSIGNED;

                SET v_depth = LENGTH(NEW.code);

                IF v_depth = 1 AND NEW.parent_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'CoA-1: a depth-1 (class root) account must not have a parent_id';
                END IF;

                IF v_depth > 1 AND NEW.parent_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'CoA-1: an account with depth > 1 must have a parent_id';
                END IF;

                IF NEW.parent_id IS NOT NULL THEN
                    SELECT code, LENGTH(code) INTO v_parent_code, v_parent_depth
                    FROM chart_of_accounts WHERE id = NEW.parent_id;

                    IF v_parent_code IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent_id does not reference an existing account';
                    END IF;

                    IF v_parent_depth <> v_depth - 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent.depth must equal depth - 1';
                    END IF;

                    IF LEFT(NEW.code, v_parent_depth) <> v_parent_code OR NEW.code = v_parent_code THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-2: parent.code must be a strict prefix of code';
                    END IF;
                END IF;
            END
        SQL);

        // CoA-5: is_system = true => code, name, name_fr are immutable
        // (02-accounting.md 1.4). display_alias exists precisely so the
        // school still has somewhere to put its own label.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_coa_before_update_coa5
            BEFORE UPDATE ON chart_of_accounts
            FOR EACH ROW
            BEGIN
                IF OLD.is_system = 1 THEN
                    IF NEW.code <> OLD.code THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-5: code is immutable on a system account';
                    END IF;
                    IF NEW.name <> OLD.name THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-5: name is immutable on a system account';
                    END IF;
                    IF NEW.name_fr <> OLD.name_fr THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-5: name_fr is immutable on a system account';
                    END IF;
                    -- is_system itself may never be turned off once seeded -
                    -- otherwise CoA-5 could be defeated by flipping the flag
                    -- first.
                    IF NEW.is_system = 0 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-5: is_system cannot be unset on a system account';
                    END IF;
                END IF;
            END
        SQL);

        // CoA-4: an account with >= 1 non-archived child must be
        // is_postable = false. Fires on child INSERT (a parent that gains its
        // first live child must already be, or become, non-postable) and on
        // child UPDATE (an archived child being un-archived, or a child being
        // re-parented under a previously-leaf account). The nightly job
        // (02-accounting.md 2.2) re-asserts across the whole tree; this
        // trigger is the write-time backstop.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_coa_before_insert_coa4
            BEFORE INSERT ON chart_of_accounts
            FOR EACH ROW
            BEGIN
                DECLARE v_parent_postable TINYINT(1);

                IF NEW.parent_id IS NOT NULL AND NEW.is_archived = 0 THEN
                    SELECT is_postable INTO v_parent_postable
                    FROM chart_of_accounts WHERE id = NEW.parent_id;

                    IF v_parent_postable = 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-4: a parent gaining a non-archived child must not be postable';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_coa_before_update_coa4
            BEFORE UPDATE ON chart_of_accounts
            FOR EACH ROW
            BEGIN
                DECLARE v_parent_postable TINYINT(1);

                IF NEW.parent_id IS NOT NULL AND NEW.is_archived = 0
                   AND (OLD.parent_id <> NEW.parent_id OR OLD.is_archived <> NEW.is_archived) THEN
                    SELECT is_postable INTO v_parent_postable
                    FROM chart_of_accounts WHERE id = NEW.parent_id;

                    IF v_parent_postable = 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'CoA-4: a parent gaining a non-archived child must not be postable';
                    END IF;
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_coa_before_insert_coa1_2');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_coa_before_update_coa1_2');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_coa_before_update_coa5');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_coa_before_insert_coa4');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_coa_before_update_coa4');

        Schema::dropIfExists('chart_of_accounts');
    }
};
