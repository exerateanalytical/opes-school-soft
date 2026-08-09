<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The statutory rate model (docs/specs/05-hr-payroll.md 4.2), fixing defect
 * H1: one row carries EITHER percentage rates (employee/employer basis
 * points, Rate::SCALE = 100 000) OR a flat amount per band (RAV, TDL) -
 * never both - plus the ceiling that applies to PVID/PF only (defect N1:
 * `ceiling_amount` NULL means UNCAPPED, and RP can never be given one; the
 * defect is made unrepresentable by CHECK, not merely documented).
 *
 * The standing rule of 05 §0: rows ship as SHELLS with the amount columns
 * NULL and `is_verified = false`. The XOR and flat_band CHECKs therefore
 * gate on `is_verified`: an unverified shell may carry all-NULL amounts
 * (that is the point of shipping empty), but the moment a row is verified -
 * i.e. visible to the engine (4.2 rule 9) - it must carry exactly one of
 * the two representations. "Never both" holds unconditionally.
 *
 * Append-only history (H7, 4.4): once `locked` is set by the first
 * approved-run reference, BEFORE UPDATE / BEFORE DELETE triggers reject
 * every write except the single permitted closure - `effective_to` going
 * from NULL to a date, every other column byte-identical. Application-layer
 * enforcement alone is not sufficient for a table this consequential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // PVID | PF | RP | IRPP | CAC | CFC | FNE | RAV | TDL
            $table->string('code', 16)->collation('utf8mb4_0900_as_cs');

            $table->string('label', 120);
            $table->string('label_fr', 120)->nullable();

            $table->enum('shape', ['percentage', 'flat_band', 'progressive_bracket']);
            $table->enum('basis', [
                'basic', 'sbt', 'gross', 'taxable', 'cnps_capped', 'cnps_uncapped', 'irpp_amount',
            ]);

            // IRPP brackets are ANNUAL (6.3) - a seed/engine mismatch here
            // is a 12x error, so the basis is data, not convention.
            $table->enum('bracket_basis', ['monthly', 'annual'])->nullable();

            // Basis points per 00-core 7.2: Rate::SCALE = 100 000 = 100%.
            $table->bigInteger('employee_rate_bp')->nullable();
            $table->bigInteger('employer_rate_bp')->nullable();

            // SIGNED whole FCFA - RAV/TDL band amounts.
            $table->bigInteger('flat_amount')->nullable();

            // NULL means UNCAPPED - the N1 fix at the schema level.
            $table->bigInteger('ceiling_amount')->nullable();
            $table->bigInteger('floor_amount')->nullable();

            // Band boundaries: from inclusive, to EXCLUSIVE, NULL = open top.
            $table->bigInteger('band_from')->nullable();
            $table->bigInteger('band_to')->nullable();

            // RP only: CNPS classifies the employer's establishment (H2).
            $table->string('risk_class', 8)->nullable();

            // PF only: 3.70% enseignement prive vs 7% general (defect N2).
            $table->enum('cnps_regime', ['general', 'agricole', 'enseignement_prive'])->nullable();

            $table->date('effective_from');
            // Exclusive, per every effective-dated table in this codebase.
            $table->date('effective_to')->nullable();

            $table->string('source_citation', 255);
            // No FK: the `documents` table belongs to Phase 13.
            $table->unsignedBigInteger('source_document_id')->nullable();

            // FALSE = invisible to the engine (4.2 rule 9). Resolution
            // treats unverified rows as absent; there is no default rate.
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();

            // Set TRUE by the approve Action on first approved-run reference.
            $table->boolean('locked')->default(false);

            $table->timestamps();

            // 4.2 constraint 8.
            $table->unique(
                ['code', 'risk_class', 'cnps_regime', 'band_from', 'effective_from'],
                'uq_statutory_rates_key',
            );
        });

        // 1a (unconditional): never BOTH rates and a flat amount.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_never_both CHECK (
                NOT ((employee_rate_bp IS NOT NULL OR employer_rate_bp IS NOT NULL)
                     AND flat_amount IS NOT NULL)
            )
        SQL);

        // 1b (verified rows): exactly one representation. Shells are exempt -
        // shipping with all amount columns NULL is the 05 §0 standing rule.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_verified_xor CHECK (
                is_verified = 0 OR (
                    (employee_rate_bp IS NOT NULL OR employer_rate_bp IS NOT NULL)
                    XOR (flat_amount IS NOT NULL)
                )
            )
        SQL);

        // 2: a verified flat band must state its boundary and amount.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_flat_band CHECK (
                is_verified = 0 OR shape <> 'flat_band'
                OR (band_from IS NOT NULL AND flat_amount IS NOT NULL)
            )
        SQL);

        // 3: progressive brackets must declare their basis (annual for IRPP).
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_bracket_basis CHECK (
                shape <> 'progressive_bracket' OR bracket_basis IS NOT NULL
            )
        SQL);

        // 4: RP can NEVER be given a ceiling (defect N1).
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_rp_uncapped CHECK (
                code <> 'RP' OR ceiling_amount IS NULL
            )
        SQL);

        // 5: RP rows are per risk class.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_rp_risk_class CHECK (
                code <> 'RP' OR risk_class IS NOT NULL
            )
        SQL);

        // 6: PF rows are per employer regime, employer-only.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_pf_regime CHECK (
                code <> 'PF' OR (cnps_regime IS NOT NULL AND employee_rate_bp IS NULL)
            )
        SQL);

        // 7: PF, RP and FNE are employer-borne only.
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_rates ADD CONSTRAINT ck_sr_employer_only CHECK (
                code NOT IN ('PF', 'RP', 'FNE') OR employee_rate_bp IS NULL
            )
        SQL);

        // ---------------------------------------------------------------
        // 4.4 append-only triggers. A locked row rejects every UPDATE
        // except `effective_to` NULL -> date with all other columns
        // unchanged (<=> is the NULL-safe equality operator), and rejects
        // every DELETE. The ">= latest referencing period end" half of the
        // closure rule is enforced in CloseAndSupersedeRate - the trigger
        // guards the shape of the write, the Action guards its meaning.
        // ---------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_statutory_rates_locked_update
            BEFORE UPDATE ON statutory_rates
            FOR EACH ROW
            BEGIN
                IF OLD.locked = 1 THEN
                    IF NOT (
                        OLD.effective_to IS NULL
                        AND NEW.effective_to IS NOT NULL
                        AND NEW.code <=> OLD.code
                        AND NEW.label <=> OLD.label
                        AND NEW.label_fr <=> OLD.label_fr
                        AND NEW.shape <=> OLD.shape
                        AND NEW.basis <=> OLD.basis
                        AND NEW.bracket_basis <=> OLD.bracket_basis
                        AND NEW.employee_rate_bp <=> OLD.employee_rate_bp
                        AND NEW.employer_rate_bp <=> OLD.employer_rate_bp
                        AND NEW.flat_amount <=> OLD.flat_amount
                        AND NEW.ceiling_amount <=> OLD.ceiling_amount
                        AND NEW.floor_amount <=> OLD.floor_amount
                        AND NEW.band_from <=> OLD.band_from
                        AND NEW.band_to <=> OLD.band_to
                        AND NEW.risk_class <=> OLD.risk_class
                        AND NEW.cnps_regime <=> OLD.cnps_regime
                        AND NEW.effective_from <=> OLD.effective_from
                        AND NEW.source_citation <=> OLD.source_citation
                        AND NEW.source_document_id <=> OLD.source_document_id
                        AND NEW.is_verified <=> OLD.is_verified
                        AND NEW.verified_by <=> OLD.verified_by
                        AND NEW.verified_at <=> OLD.verified_at
                        AND NEW.locked = 1
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                            'Statutory rates are append-only once referenced by an approved run (05-hr-payroll 4.4); close the row and insert a successor';
                    END IF;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_statutory_rates_locked_delete
            BEFORE DELETE ON statutory_rates
            FOR EACH ROW
            BEGIN
                IF OLD.locked = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'Statutory rates are append-only once referenced by an approved run (05-hr-payroll 4.4); rows are closed, never deleted';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_statutory_rates_locked_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_statutory_rates_locked_delete');
        Schema::dropIfExists('statutory_rates');
    }
};
