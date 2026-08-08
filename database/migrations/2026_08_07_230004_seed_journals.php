<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 02-accounting §3: "Seeded journals: VE ventes, AC achats, CA caisse,
     * BQ banque, MM mobile money, PA paie, OD operations diverses, AN
     * a-nouveaux, CL cloture. AN and CL are is_system and only writable by
     * the year-end Actions and the opening-balance import."
     *
     * CA/BQ/MM ship is_active = false (see the previous migration's
     * comment): their treasury_account_id cannot be seeded correctly
     * because no chart-of-accounts row identifies THIS school's specific
     * bank/till/e-money float yet. An accountant activates each one via
     * ConfigureJournal once the treasury account exists.
     */
    public function up(): void
    {
        $now = now();

        $journals = [
            ['code' => 'VE', 'name' => 'Sales', 'name_fr' => 'Ventes', 'type' => 'sales', 'is_active' => true, 'is_system' => false],
            ['code' => 'AC', 'name' => 'Purchases', 'name_fr' => 'Achats', 'type' => 'purchases', 'is_active' => true, 'is_system' => false],
            ['code' => 'CA', 'name' => 'Cash', 'name_fr' => 'Caisse', 'type' => 'cash', 'is_active' => false, 'is_system' => false],
            ['code' => 'BQ', 'name' => 'Bank', 'name_fr' => 'Banque', 'type' => 'bank', 'is_active' => false, 'is_system' => false],
            ['code' => 'MM', 'name' => 'Mobile money', 'name_fr' => 'Mobile money', 'type' => 'mobile_money', 'is_active' => false, 'is_system' => false],
            ['code' => 'PA', 'name' => 'Payroll', 'name_fr' => 'Paie', 'type' => 'payroll', 'is_active' => true, 'is_system' => false],
            ['code' => 'OD', 'name' => 'Miscellaneous operations', 'name_fr' => 'Operations diverses', 'type' => 'operations_diverses', 'is_active' => true, 'is_system' => false],
            ['code' => 'AN', 'name' => 'Opening balances', 'name_fr' => 'A-nouveaux', 'type' => 'opening', 'is_active' => true, 'is_system' => true],
            ['code' => 'CL', 'name' => 'Year-end closing', 'name_fr' => 'Cloture', 'type' => 'closing', 'is_active' => true, 'is_system' => true],
        ];

        foreach ($journals as $journal) {
            DB::table('journals')->insert([
                'code' => $journal['code'],
                'name' => $journal['name'],
                'name_fr' => $journal['name_fr'],
                'type' => $journal['type'],
                'default_debit_account_id' => null,
                'default_credit_account_id' => null,
                'treasury_account_id' => null,
                'requires_maker_checker' => false,
                'piece_no_format' => '{journal}/{fy}/{seq:6}',
                'is_system' => $journal['is_system'],
                'is_active' => $journal['is_active'],
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('journals')->whereIn('code', ['VE', 'AC', 'CA', 'BQ', 'MM', 'PA', 'OD', 'AN', 'CL'])->delete();
    }
};
