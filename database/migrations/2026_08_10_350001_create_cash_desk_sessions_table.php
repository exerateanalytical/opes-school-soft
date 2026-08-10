<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.7 + §17.2, docs/specs/02-accounting.md §11.5 -
 * the cash-desk session (« la caisse »), the one thing Fees had no table for.
 *
 * Without it a school cannot answer the only question a bursar is ever asked
 * at 17:00: *is the money in the tin the money the system says should be in
 * the tin?* v1 could not, because a cash collection named no float and no
 * shift; every note taken between 08:00 and 17:00 vanished into one
 * undifferentiated "cash in hand" balance that nobody could count against.
 *
 * A session is scoped to ONE cash box - a `chart_of_accounts` class-5 row
 * whose code sits in the 57 *Caisse* family - because that is what a
 * physical till IS in SYSCOHADA (the same modelling decision 320001 made for
 * `payments.treasury_account_id`; there is deliberately no parallel
 * "treasury registry" table).
 *
 * §11.7's "one open session per (user, business_date)" is enforced by the
 * DATABASE, not by a check-then-insert race: `open_session_key` is a STORED
 * generated column that is NULL for every closed session (and MySQL treats
 * each NULL in a unique index as distinct) and `opened_by:business_date` for
 * an open one. Two browser tabs pressing "Open session" concurrently produce
 * a duplicate-key error, never two open tills.
 *
 * Money is BIGINT **signed** FCFA throughout - `variance` is signed on
 * purpose: a shortage is negative, an overage positive, and the sign is the
 * whole meaning (02-accounting §11.5 posts them to different accounts).
 *
 * `variance_reason` mandatory-when-variance-non-zero is a CHECK, not a
 * validation rule, because "the till was 5 000 short and nobody wrote down
 * why" is precisely the state this feature exists to make unrepresentable.
 *
 * Deletes are RESTRICT everywhere (00-core §9): a session is a financial
 * record; it closes, it never disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_desk_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CDS/2026/000001, allocated from the row-locked
            // `cash_desk_session_no` sequence inside the opening transaction
            // (00-core §12, gaps permitted) - never max()+1.
            $table->string('session_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_cash_desk_sessions_no');

            // THE cash box this shift is responsible for. Class 5, postable,
            // 57x - validated in OpenCashDeskSession, which can dereference
            // the FK; a CHECK cannot.
            $table->foreignId('treasury_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // 00-core §7.5 / BusinessDate: Africa/Douala, never UTC. A note
            // taken at 00:30 belongs to today's cash book, not yesterday's.
            $table->date('business_date');

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->bigInteger('opening_float')->default(0);

            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('closed_at')->nullable();

            // expected = opening_float + the session's own live collections,
            // recomputed at close from the payments themselves; counted is
            // what the human actually counted; variance = counted − expected.
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->string('variance_reason', 400)->nullable();

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('open');

            // The `cashdesk.closed_with_variance` entry, when there was one.
            // NULL is the normal, happy case: a till that balanced posts
            // nothing at all.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_date', 'treasury_account_id'], 'ix_cash_desk_sessions_date_box');
            $table->index(['opened_by', 'status'], 'ix_cash_desk_sessions_cashier');
            $table->index('status', 'ix_cash_desk_sessions_status');
        });

        // §11.7 "one open session per (user, business_date) - generated-column
        // UNIQUE". Added outside the Blueprint because the expression must be
        // written verbatim.
        DB::statement(
            'ALTER TABLE `cash_desk_sessions` '
            ."ADD COLUMN `open_session_key` VARCHAR(40) COLLATE utf8mb4_0900_as_cs "
            ."GENERATED ALWAYS AS (IF(`status` = 'open', CONCAT(`opened_by`, ':', `business_date`), NULL)) STORED"
        );

        DB::statement(
            'ALTER TABLE `cash_desk_sessions` '
            .'ADD UNIQUE INDEX `uq_cash_desk_sessions_open` (`open_session_key`)'
        );

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `cash_desk_sessions` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_desk_sessions');
    }

    /**
     * The invariants that must hold whatever writes the row - an Action, a
     * console command, a DBA at 02:00.
     *
     * @var array<string, string>
     */
    private const CHECKS = [
        'ck_cash_desk_sessions_status' => "`status` IN ('open','closed','reconciled')",

        // A float is what the school put IN; it is never negative, and a
        // counted till is never negative either.
        'ck_cash_desk_sessions_float' => '`opening_float` >= 0',
        'ck_cash_desk_sessions_counted' => '`counted_cash` IS NULL OR `counted_cash` >= 0',

        // The arithmetic itself, so no code path can store a variance that
        // does not follow from the two numbers beside it.
        'ck_cash_desk_sessions_variance_maths' =>
            '`variance` IS NULL OR (`counted_cash` IS NOT NULL AND `expected_cash` IS NOT NULL '
            .'AND `variance` = `counted_cash` - `expected_cash`)',

        // §11.7: mandatory when variance <> 0.
        'ck_cash_desk_sessions_variance_reason' =>
            "`variance` IS NULL OR `variance` = 0 OR (`variance_reason` IS NOT NULL AND `variance_reason` <> '')",

        // A closed session is a complete one; an open one has none of the
        // closing facts yet.
        'ck_cash_desk_sessions_closed_complete' =>
            "`status` = 'open' OR (`closed_by` IS NOT NULL AND `closed_at` IS NOT NULL "
            .'AND `counted_cash` IS NOT NULL AND `expected_cash` IS NOT NULL AND `variance` IS NOT NULL)',

        'ck_cash_desk_sessions_open_clean' =>
            "`status` <> 'open' OR (`closed_by` IS NULL AND `closed_at` IS NULL "
            .'AND `counted_cash` IS NULL AND `variance` IS NULL AND `journal_entry_id` IS NULL)',
    ];
};
