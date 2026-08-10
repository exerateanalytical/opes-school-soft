<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.1 `ReconciliationMatch` - the assertion
 * "these statement lines and these ledger lines are the same money".
 *
 * §13.1 describes the two sides as JSON arrays and then, in BR-2, asks for
 * "UNIQUE on the join tables backing the JSON". Storing both would give the
 * same fact two homes that can disagree, so the join tables ARE the storage
 * and there is no JSON column: `uq_rmsl_statement_line` and
 * `uq_rmll_ledger_line` then make BR-2 - "a statement line and a ledger line
 * each belong to at most one match" - structurally impossible to violate,
 * rather than a rule an Action remembers to check.
 *
 * A match carries no side of its own: `amount` is the signed money-in figure
 * both sides agree on (BR-1), and it is proved in PHP with Money by
 * MatchReconciliationLines before this row is written.
 *
 * `confidence_bp` is basis points, 0-10000, so the auto-matcher's score is
 * an integer like every other number here - a float in a financial table
 * invites a float in an amount.
 *
 * Unmatching DELETES the match (§13.1: "unmatching deletes the match, never
 * the ledger line"), so the join rows cascade from it. That is the one place
 * house-style RESTRICT would be actively wrong: these rows have no meaning
 * without their parent, and leaving them behind would make BR-2 permanently
 * unsatisfiable for those lines.
 *
 * Two structural notes on HOW this is written:
 *
 *  - every object is created only if it is absent. Not defensive padding:
 *    this migration first ran interleaved with another agent's `artisan
 *    migrate` against the same development database and, on top of that,
 *    Laravel's auto-generated FK names for these long table names exceed
 *    MySQL's 64-character identifier limit - so the first attempt left the
 *    three tables behind, indexless, WITHOUT recording the migration. The
 *    house rule here forbids DROP against `opeschool`, so "drop and re-run"
 *    is not an available repair; this migration converges from that
 *    half-state instead of demanding a destructive one;
 *  - every index and foreign key is therefore named EXPLICITLY and added
 *    after the fact, which is also what keeps those names inside 64
 *    characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reconciliation_matches')) {
            Schema::create('reconciliation_matches', function (Blueprint $table): void {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('reconciliation_session_id');

                $table->string('match_type', 20)->collation('utf8mb4_0900_as_cs')->default('one_to_one');

                // Signed, money-in positive: +92 500 for a receipt, −5 250
                // for a commission. Both sides equal it (BR-1).
                $table->bigInteger('amount');

                $table->boolean('is_auto')->default(false);
                $table->unsignedSmallInteger('confidence_bp')->default(10000);

                $table->unsignedBigInteger('matched_by');
                $table->dateTime('matched_at');

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reconciliation_match_statement_lines')) {
            Schema::create('reconciliation_match_statement_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reconciliation_match_id');
                $table->unsignedBigInteger('bank_statement_line_id');
            });
        }

        if (! Schema::hasTable('reconciliation_match_ledger_lines')) {
            Schema::create('reconciliation_match_ledger_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reconciliation_match_id');
                $table->unsignedBigInteger('journal_entry_line_id');
            });
        }

        $this->addIndex('reconciliation_matches', 'ix_reconciliation_matches_session', '(`reconciliation_session_id`, `is_auto`)');

        // BR-2, both halves - the whole reason these tables exist.
        $this->addIndex('reconciliation_match_statement_lines', 'uq_rmsl_statement_line', '(`bank_statement_line_id`)', unique: true);
        $this->addIndex('reconciliation_match_ledger_lines', 'uq_rmll_ledger_line', '(`journal_entry_line_id`)', unique: true);

        // Supporting index for the cascade and for "the lines of this match".
        $this->addIndex('reconciliation_match_statement_lines', 'ix_rmsl_match', '(`reconciliation_match_id`)');
        $this->addIndex('reconciliation_match_ledger_lines', 'ix_rmll_match', '(`reconciliation_match_id`)');

        foreach (self::FOREIGN_KEYS as $name => $fk) {
            if ($this->constraintExists($name)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
                $fk['table'],
                $name,
                $fk['column'],
                $fk['references'],
                $fk['on_delete'],
            ));
        }

        foreach (self::CHECKS as $name => $expression) {
            if ($this->constraintExists($name)) {
                continue;
            }

            DB::statement("ALTER TABLE `reconciliation_matches` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_match_ledger_lines');
        Schema::dropIfExists('reconciliation_match_statement_lines');
        Schema::dropIfExists('reconciliation_matches');
    }

    private function addIndex(string $table, string $name, string $columns, bool $unique = false): void
    {
        $exists = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();

        if ($exists) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` %s',
            $table,
            $unique ? 'UNIQUE ' : '',
            $name,
            $columns,
        ));
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    /**
     * @var array<string, array{table: string, column: string, references: string, on_delete: string}>
     */
    private const FOREIGN_KEYS = [
        'fk_rm_session' => [
            'table' => 'reconciliation_matches',
            'column' => 'reconciliation_session_id',
            'references' => 'reconciliation_sessions',
            'on_delete' => 'RESTRICT',
        ],
        'fk_rm_matched_by' => [
            'table' => 'reconciliation_matches',
            'column' => 'matched_by',
            'references' => 'users',
            'on_delete' => 'RESTRICT',
        ],
        'fk_rmsl_match' => [
            'table' => 'reconciliation_match_statement_lines',
            'column' => 'reconciliation_match_id',
            'references' => 'reconciliation_matches',
            'on_delete' => 'CASCADE',
        ],
        'fk_rmsl_statement_line' => [
            'table' => 'reconciliation_match_statement_lines',
            'column' => 'bank_statement_line_id',
            'references' => 'bank_statement_lines',
            'on_delete' => 'RESTRICT',
        ],
        'fk_rmll_match' => [
            'table' => 'reconciliation_match_ledger_lines',
            'column' => 'reconciliation_match_id',
            'references' => 'reconciliation_matches',
            'on_delete' => 'CASCADE',
        ],
        'fk_rmll_ledger_line' => [
            'table' => 'reconciliation_match_ledger_lines',
            'column' => 'journal_entry_line_id',
            'references' => 'journal_entry_lines',
            'on_delete' => 'RESTRICT',
        ],
    ];

    /** @var array<string, string> */
    private const CHECKS = [
        'ck_reconciliation_matches_type' =>
            "`match_type` IN ('one_to_one','one_to_many','many_to_one','many_to_many')",
        'ck_reconciliation_matches_confidence' => '`confidence_bp` <= 10000',
    ];
};
