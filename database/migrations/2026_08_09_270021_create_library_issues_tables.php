<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.4 - the constraint that matters.
 *
 * `open_copy_key` is GENERATED: the copy id while the issue is open or
 * overdue, NULL otherwise. `UNIQUE uq_open_issue` on it is the 00-core
 * §10.1 pattern - THE LAST COPY CANNOT BE ISSUED TWICE, enforced by the
 * database, not by a check-then-act read. IssueBook still locks copy row
 * then member row (fixed order, 00-core §11); the unique key is the last
 * line of defence under a race.
 *
 * `status='overdue'` is a persisted state promoted by the nightly job -
 * never a computed `due_on < today` - which is what makes "87 Overdue
 * Books" queryable and fine accrual deterministic.
 *
 * `library_renewals` is append-only (trigger-enforced, same discipline as
 * asset_custody_movements). `library_reservations` reserve a TITLE, not a
 * copy; the queue head is promoted at return time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_issues', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('issue_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_library_issues_no');

            $table->foreignId('book_copy_id')
                ->constrained('book_copies')->restrictOnDelete();
            $table->foreignId('library_member_id')
                ->constrained('library_members')->restrictOnDelete();

            $table->date('issued_on');
            $table->date('due_on');

            $table->foreignId('issued_by')
                ->constrained('users')->restrictOnDelete();

            $table->date('returned_on')->nullable();
            $table->foreignId('received_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->unsignedSmallInteger('renewal_count')->default(0);

            $table->enum('status', ['open', 'returned', 'overdue', 'lost', 'written_off'])
                ->default('open');

            $table->enum('return_condition', ['good', 'damaged', 'lost'])->nullable();

            $table->string('idempotency_key', 100)->nullable()
                ->unique('uq_library_issues_idem');

            $table->timestamps();

            $table->index(['library_member_id', 'status'], 'ix_library_issues_member_status');
            $table->index(['status', 'due_on'], 'ix_library_issues_status_due');
        });

        // The §10.4 generated column + uq_open_issue.
        DB::statement(
            "ALTER TABLE library_issues ADD COLUMN open_copy_key BIGINT UNSIGNED "
            ."GENERATED ALWAYS AS (CASE WHEN status IN ('open','overdue') THEN book_copy_id END) STORED"
        );
        DB::statement(
            'ALTER TABLE library_issues ADD CONSTRAINT uq_open_issue UNIQUE (open_copy_key)'
        );

        Schema::create('library_renewals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('library_issue_id')
                ->constrained('library_issues')->restrictOnDelete();

            $table->date('renewed_on');
            $table->date('previous_due_on');
            $table->date('new_due_on');

            $table->foreignId('renewed_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('library_issue_id', 'ix_library_renewals_issue');
        });

        // Append-only (§10.4): a renewal is history, never edited.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_library_renewals_before_update
            BEFORE UPDATE ON library_renewals
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'library_renewals is append-only: corrections are new rows';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_library_renewals_before_delete
            BEFORE DELETE ON library_renewals
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'library_renewals is append-only: rows are never deleted';
            END
        SQL);

        Schema::create('library_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Title-level, not copy-level (§10.4): a member reserves *a* copy.
            $table->foreignId('book_id')
                ->constrained('books')->restrictOnDelete();
            $table->foreignId('library_member_id')
                ->constrained('library_members')->restrictOnDelete();

            $table->date('reserved_on');
            $table->date('expires_on')->nullable();

            $table->enum('status', ['waiting', 'ready', 'fulfilled', 'expired', 'cancelled'])
                ->default('waiting');

            // Set when a returned copy is parked for this reservation.
            $table->foreignId('book_copy_id')->nullable()
                ->constrained('book_copies')->restrictOnDelete();

            $table->timestamp('notified_at')->nullable();
            $table->unsignedSmallInteger('position');

            $table->timestamps();

            $table->index(['book_id', 'status', 'position'], 'ix_library_reservations_queue');
        });

        // One live reservation per (book, member): the generated flag is 1
        // while waiting/ready and NULL otherwise, so settled reservations
        // fall out of the unique key (the NULL-unique trick).
        DB::statement(
            "ALTER TABLE library_reservations ADD COLUMN active_key TINYINT "
            ."GENERATED ALWAYS AS (CASE WHEN status IN ('waiting','ready') THEN 1 END) STORED"
        );
        DB::statement(
            'ALTER TABLE library_reservations ADD CONSTRAINT uq_live_reservation UNIQUE (book_id, library_member_id, active_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('library_reservations');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_library_renewals_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_library_renewals_before_delete');
        Schema::dropIfExists('library_renewals');
        Schema::dropIfExists('library_issues');
    }
};
