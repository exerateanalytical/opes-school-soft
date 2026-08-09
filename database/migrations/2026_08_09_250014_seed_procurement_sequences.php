<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/03-tax-procurement.md §9 - register the Phase 5 document
 * series in the `sequences` table.
 *
 * All are GAPS-PERMITTED and GLOBALLY UNIQUE per series across fiscal years
 * (the year embedded in formats like `BC/2026/000123` is legibility only, so
 * the counter never resets). SequenceAllocator would lazily create any of
 * these on first use; seeding them here makes the §9 register explicit and
 * reviewable instead of implicit in seven different Actions.
 *
 *   FRN - supplier code            REQ - purchase requisition
 *   BC  - bon de commande (PO)     BR  - bon de reception (goods receipt)
 *   FF  - facture fournisseur      AVF - avoir fournisseur (credit note)
 *   PF  - paiement fournisseur     ATT - attestation de retenue
 *
 * insertOrIgnore: re-running (or a test DB where an Action already touched a
 * series) must never reset a counter.
 */
return new class extends Migration
{
    private const SERIES = ['FRN', 'REQ', 'BC', 'BR', 'FF', 'AVF', 'PF', 'ATT'];

    public function up(): void
    {
        foreach (self::SERIES as $series) {
            DB::table('sequences')->insertOrIgnore([
                'series' => $series,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only remove counters that were never consumed: dropping an advanced
        // counter would let a re-migration re-issue document numbers.
        DB::table('sequences')
            ->whereIn('series', self::SERIES)
            ->where('next_value', 1)
            ->delete();
    }
};
