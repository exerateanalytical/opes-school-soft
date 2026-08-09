<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE SNAPSHOT IS AUTHORITATIVE (docs/specs/05-hr-payroll.md 10, fixing C7).
 *
 * Written once, at approval. Payslip re-render, DIPE re-export, the
 * registre d'employeur "as at" view and every audit read read THIS row and
 * never recompute; recomputation exists only inside calculate, on a draft
 * run. The payload is denormalised and self-contained - it copies the rate
 * rows' AMOUNT COLUMNS, not just their FKs, so it stays readable after a
 * decade even if the rate table is migrated.
 *
 * INSERT-only is enforced in the storage engine: BEFORE UPDATE and BEFORE
 * DELETE triggers reject UNCONDITIONALLY (10.2). Unlike statutory_rates,
 * there is no permitted closure write - nothing about an approved payslip
 * is ever mutable, including by a reversal (the reversal contrepasses the
 * ledger and cancels the run; the snapshot remains readable forever, 8.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_item_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_item_id')->unique();
            $table->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();

            $table->unsignedInteger('snapshot_version');

            // The 10.2 payload: employer block, employee block, period and
            // days, every line with base/rate/rate-row copy, employer
            // contributions, YTD figures, leave balance, component set with
            // calculation_order and formulas, template version.
            $table->longText('payload');

            $table->char('payload_hash', 64);

            // Set on first payslip render (10.3 byte-identity gate).
            $table->char('rendered_pdf_hash', 64)->nullable();

            $table->timestamp('created_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_item_snapshots_no_update
            BEFORE UPDATE ON payroll_item_snapshots
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'PayrollItemSnapshot is INSERT-only (05-hr-payroll 10.2); the snapshot is authoritative and is never mutated';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_item_snapshots_no_delete
            BEFORE DELETE ON payroll_item_snapshots
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'PayrollItemSnapshot is INSERT-only (05-hr-payroll 10.2); snapshots remain readable forever, even after reversal';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_item_snapshots_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_item_snapshots_no_delete');
        Schema::dropIfExists('payroll_item_snapshots');
    }
};
