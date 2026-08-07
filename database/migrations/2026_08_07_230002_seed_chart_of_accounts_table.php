<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the SYSCOHADA revise class skeleton plus every account explicitly
 * named in docs/specs/02-accounting.md 2.3's "Verified and seeded" table.
 *
 * This ships as a migration, not a re-runnable seeder, per this project's
 * convention of keeping statutory reference data in a migration so it lands
 * with `migrate` on every install (see the migration's own filename note in
 * the phase brief).
 *
 * 00-core 16 / 02-accounting.md 2.3: **a wrong seeded value is more dangerous
 * than an empty one.** Nothing from the "NEEDS VERIFICATION" table is seeded
 * here - not the 707x boarding/transport/canteen split, not the 706 5-digit
 * tuition extensions, not 658/758, 491, 845 (and NOT the commonly-cited-but-
 * wrong "865"), 151, 106, or 428x. ChartOfAccountSeedTest asserts their
 * absence directly against this table.
 *
 * Structural scaffolding (the 2-digit and some 3-digit accounts that are not
 * themselves in the "Verified and seeded" table but must exist so the
 * explicitly-listed accounts have a valid CoA-2 parent chain - e.g. "24"
 * Materiel, "244" Materiel et mobilier, above 2441/2442) uses standard,
 * undisputed SYSCOHADA plan-comptable labels - not the ambiguous items the
 * spec flags NEEDS VERIFICATION.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        /**
         * @var list<array{
         *     code: string,
         *     name: string,
         *     name_fr: string,
         *     type: string,
         *     normal_balance: string,
         *     is_postable: bool,
         *     is_collective?: bool,
         *     requires_partner?: bool,
         *     allowed_partner_types?: list<string>,
         *     is_lettrable?: bool,
         *     is_reconcilable?: bool,
         *     citation: string,
         * }>
         */
        $accounts = [
            // ---- Class 1 - Comptes de ressources durables ----
            ['code' => '1', 'name' => 'Permanent resources', 'name_fr' => 'Comptes de ressources durables', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 1 (class root).'],
            ['code' => '11', 'name' => 'Retained earnings brought forward', 'name_fr' => 'Report a nouveau', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 1; 02-accounting.md 2.3.'],
            ['code' => '12', 'name' => 'Net result for the period', 'name_fr' => 'Resultat net de l\'exercice', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 1; 02-accounting.md 2.3.'],
            ['code' => '13', 'name' => 'Result pending appropriation', 'name_fr' => 'Resultat en instance d\'affectation', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 1; 02-accounting.md 2.3.'],
            ['code' => '14', 'name' => 'Investment grants', 'name_fr' => 'Subventions d\'investissement', 'type' => 'equity', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 1; 02-accounting.md 2.3, used by 06-assets-stores.'],

            // ---- Class 2 - Comptes d'actif immobilise ----
            ['code' => '2', 'name' => 'Fixed assets', 'name_fr' => 'Comptes d\'actif immobilise', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 2 (class root).'],
            ['code' => '24', 'name' => 'Equipment', 'name_fr' => 'Materiel', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; structural parent of 244/249.'],
            ['code' => '244', 'name' => 'Equipment and furniture', 'name_fr' => 'Materiel et mobilier', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; structural parent of 2441/2442.'],
            ['code' => '2441', 'name' => 'Office equipment', 'name_fr' => 'Materiel de bureau', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; 02-accounting.md 1.1 - desks/chairs/office equipment, NOT the ICT lab.'],
            ['code' => '2442', 'name' => 'IT equipment', 'name_fr' => 'Materiel informatique', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; 02-accounting.md 1.1 - the corrected code for computers/servers/networking; AssetCategory ICT points here, not 2441.'],
            ['code' => '249', 'name' => 'Advances and deposits paid on fixed assets', 'name_fr' => 'Avances et acomptes verses sur immobilisations', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; 02-accounting.md 2.3, used by 06-assets-stores (assets under construction).'],
            ['code' => '28', 'name' => 'Depreciation', 'name_fr' => 'Amortissements', 'type' => 'asset', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; 02-accounting.md 2.3, used by 06-assets-stores. Contra-asset: asset type with a credit normal balance.'],
            ['code' => '29', 'name' => 'Impairment of fixed assets', 'name_fr' => 'Depreciations des immobilisations', 'type' => 'asset', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 2; 02-accounting.md 2.3, used by 06-assets-stores. Contra-asset: asset type with a credit normal balance.'],

            // ---- Class 3 - Comptes de stocks ----
            ['code' => '3', 'name' => 'Inventory', 'name_fr' => 'Comptes de stocks', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 3 (class root).'],
            ['code' => '31', 'name' => 'Goods for resale', 'name_fr' => 'Marchandises', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 3; 02-accounting.md 2.3/11.4.'],
            ['code' => '32', 'name' => 'Raw materials', 'name_fr' => 'Matieres premieres', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 3; 02-accounting.md 2.3/11.4.'],
            ['code' => '33', 'name' => 'Other supplies', 'name_fr' => 'Autres approvisionnements', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 3; 02-accounting.md 2.3/11.4.'],

            // ---- Class 4 - Comptes de tiers ----
            ['code' => '4', 'name' => 'Third parties', 'name_fr' => 'Comptes de tiers', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4 (class root).'],

            ['code' => '40', 'name' => 'Suppliers and related accounts', 'name_fr' => 'Fournisseurs et comptes rattaches', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 401.'],
            ['code' => '401', 'name' => 'Suppliers, trade payables', 'name_fr' => 'Fournisseurs, dettes en compte', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['supplier'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, owned by 03-tax-procurement.'],

            ['code' => '41', 'name' => 'Clients and related accounts', 'name_fr' => 'Clients et comptes rattaches', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 411/416/418/419.'],
            ['code' => '411', 'name' => 'Clients', 'name_fr' => 'Clients', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 8.1 - subdivides by counterparty CATEGORY, not by individual.'],
            ['code' => '4111', 'name' => 'Clients', 'name_fr' => 'Clients', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/8.1.'],
            ['code' => '4112', 'name' => 'Clients - Group', 'name_fr' => 'Clients - Groupe', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/8.1.'],
            ['code' => '4114', 'name' => 'Clients - State', 'name_fr' => 'Clients - Etat', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/8.1.'],
            ['code' => '416', 'name' => 'Doubtful or disputed clients', 'name_fr' => 'Clients douteux ou litigieux', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 4161/4162.'],
            ['code' => '4161', 'name' => 'Disputed receivables', 'name_fr' => 'Creances litigieuses', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees.'],
            ['code' => '4162', 'name' => 'Doubtful receivables', 'name_fr' => 'Creances douteuses', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees.'],
            ['code' => '418', 'name' => 'Clients, unbilled revenue', 'name_fr' => 'Clients, produits non encore etablis', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 4181.'],
            ['code' => '4181', 'name' => 'Clients, invoices to be issued', 'name_fr' => 'Clients, factures a etablir', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees.'],
            ['code' => '419', 'name' => 'Clients, credit balances', 'name_fr' => 'Clients crediteurs', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 4191/4198.'],
            ['code' => '4191', 'name' => 'Clients, advances and deposits received', 'name_fr' => 'Clients, avances et acomptes recus', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees.'],
            ['code' => '4198', 'name' => 'Rebates, discounts and other credits to grant', 'name_fr' => 'Rabais, remises, ristournes et autres avoirs a accorder', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['student', 'guardian', 'organisation'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees.'],

            ['code' => '47', 'name' => 'Sundry debtors and creditors', 'name_fr' => 'Debiteurs et crediteurs divers', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, used by 04-fees (agent/third-party funds).'],
            ['code' => '476', 'name' => 'Prepaid expenses', 'name_fr' => 'Charges constatees d\'avance', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/17.4.'],
            ['code' => '477', 'name' => 'Deferred revenue', 'name_fr' => 'Produits constates d\'avance', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/17.4.'],

            ['code' => '48', 'name' => 'Payables related to fixed assets', 'name_fr' => 'Dettes liees aux immobilisations', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; structural parent of 481/485.'],
            ['code' => '481', 'name' => 'Investment suppliers', 'name_fr' => 'Fournisseurs d\'investissements', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => false, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['supplier'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3, owned by 03-tax-procurement.'],
            ['code' => '4812', 'name' => 'Investment suppliers, trade payables', 'name_fr' => 'Fournisseurs d\'investissements, dettes en compte', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['supplier'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3.'],
            ['code' => '4817', 'name' => 'Investment suppliers, retention held', 'name_fr' => 'Fournisseurs d\'investissements, retenues de garantie', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['supplier'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3.'],
            ['code' => '4818', 'name' => 'Investment suppliers, invoices not yet received', 'name_fr' => 'Fournisseurs d\'investissements, factures non parvenues', 'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true, 'is_collective' => true, 'requires_partner' => true, 'allowed_partner_types' => ['supplier'], 'is_lettrable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3.'],
            ['code' => '485', 'name' => 'Receivables on disposal of fixed assets', 'name_fr' => 'Creances sur cessions d\'immobilisations', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 4; 02-accounting.md 2.3/11.6.'],

            // ---- Class 5 - Comptes de tresorerie ----
            ['code' => '5', 'name' => 'Treasury', 'name_fr' => 'Comptes de tresorerie', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 5 (class root).'],
            ['code' => '52', 'name' => 'Banks', 'name_fr' => 'Banques', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_reconcilable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 5; 02-accounting.md 2.3/13.'],
            ['code' => '55', 'name' => 'Electronic money instruments', 'name_fr' => 'Instruments de monnaie electronique', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 5; 02-accounting.md 1.3 - mobile money is NOT a bank account (not 5210).'],
            ['code' => '552', 'name' => 'Mobile phone', 'name_fr' => 'Telephone Portable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_reconcilable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 5; 02-accounting.md 1.3 - MTN MoMo / Orange Money home.'],
            ['code' => '57', 'name' => 'Cash', 'name_fr' => 'Caisse', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_reconcilable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 5; 02-accounting.md 2.3/11.5.'],

            // ---- Class 6 - Comptes de charges des activites ordinaires ----
            ['code' => '6', 'name' => 'Ordinary operating expenses', 'name_fr' => 'Comptes de charges des activites ordinaires', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 6 (class root).'],
            ['code' => '60', 'name' => 'Purchases and stock variations', 'name_fr' => 'Achats et variations de stocks', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; structural parent of 601/602/603/604.'],
            ['code' => '601', 'name' => 'Purchases of goods for resale', 'name_fr' => 'Achats de marchandises', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '602', 'name' => 'Purchases of raw materials and related supplies', 'name_fr' => 'Achats de matieres premieres et fournitures liees', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '603', 'name' => 'Stock variations on purchased goods', 'name_fr' => 'Variations des stocks de biens achetes', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; structural parent of 6031/6032/6033.'],
            ['code' => '6031', 'name' => 'Stock variation - goods for resale', 'name_fr' => 'Variations des stocks de marchandises', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '6032', 'name' => 'Stock variation - raw materials', 'name_fr' => 'Variations des stocks de matieres premieres', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '6033', 'name' => 'Stock variation - other supplies', 'name_fr' => 'Variations des stocks des autres approvisionnements', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '604', 'name' => 'Purchases of consumable materials and supplies', 'name_fr' => 'Achats stockes de matieres et fournitures consommables', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 2.3/11.4.'],
            ['code' => '63', 'name' => 'Outside services', 'name_fr' => 'Services exterieurs', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; structural parent of 631.'],
            ['code' => '631', 'name' => 'Bank charges', 'name_fr' => 'Frais bancaires', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; structural parent of 6317.'],
            ['code' => '6317', 'name' => 'Charges on bills and other bank charges', 'name_fr' => 'Frais sur effets et autres frais bancaires', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 6; 02-accounting.md 1.3/11.3 - mobile-money operator commission; exact 631x label to be confirmed by the school\'s accountant, code confirmed.'],

            // ---- Class 7 - Comptes de produits des activites ordinaires ----
            ['code' => '7', 'name' => 'Ordinary operating revenue', 'name_fr' => 'Comptes de produits des activites ordinaires', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 7 (class root).'],
            ['code' => '70', 'name' => 'Sales', 'name_fr' => 'Ventes', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; structural parent of 701/706/707.'],
            ['code' => '701', 'name' => 'Sales of goods for resale', 'name_fr' => 'Ventes de marchandises', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 2.3, used by 06-assets-stores.'],
            ['code' => '706', 'name' => 'Services sold', 'name_fr' => 'Services vendus', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 1.2 - the 4th digit encodes GEOGRAPHY, not service type. Tuition extends at 5+ digits (NOT seeded here - NEEDS VERIFICATION per 2.3).'],
            ['code' => '707', 'name' => 'Incidental income', 'name_fr' => 'Produits accessoires', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 1.2 - boarding/transport/canteen belong here, not 706.'],
            ['code' => '7073', 'name' => 'Rentals', 'name_fr' => 'Locations', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 1.2/2.3 verified subdivision.'],
            ['code' => '7077', 'name' => 'Bonuses on recovery and disposal of returnable packaging', 'name_fr' => 'Bonis sur reprises et cessions d\'emballages', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 1.2/2.3 verified subdivision.'],
            ['code' => '7078', 'name' => 'Other incidental income', 'name_fr' => 'Autres produits accessoires', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 7; 02-accounting.md 1.2/2.3 verified subdivision.'],

            // ---- Class 8 - Autres charges et produits (HAO) ----
            ['code' => '8', 'name' => 'Other expenses and income (HAO)', 'name_fr' => 'Comptes des autres charges et produits', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 8 (class root).'],
            ['code' => '81', 'name' => 'Net book value of disposed fixed assets', 'name_fr' => 'Valeurs comptables des cessions d\'immobilisations', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '811', 'name' => 'Net book value - intangible assets disposed', 'name_fr' => 'Valeurs comptables des cessions d\'immobilisations incorporelles', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '812', 'name' => 'Net book value - tangible assets disposed', 'name_fr' => 'Valeurs comptables des cessions d\'immobilisations corporelles', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '816', 'name' => 'Net book value - financial assets disposed', 'name_fr' => 'Valeurs comptables des cessions d\'immobilisations financieres', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '82', 'name' => 'Proceeds on disposal of fixed assets', 'name_fr' => 'Produits des cessions d\'immobilisations', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '821', 'name' => 'Proceeds - intangible assets disposed', 'name_fr' => 'Produits des cessions d\'immobilisations incorporelles', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '822', 'name' => 'Proceeds - tangible assets disposed', 'name_fr' => 'Produits des cessions d\'immobilisations corporelles', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '826', 'name' => 'Proceeds - financial assets disposed', 'name_fr' => 'Produits des cessions d\'immobilisations financieres', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/11.6.'],
            ['code' => '89', 'name' => 'Income taxes', 'name_fr' => 'Impots sur le resultat', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/17.6.'],
            ['code' => '891', 'name' => 'Income tax for the period', 'name_fr' => 'Impots sur les benefices de l\'exercice', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/17.6.'],
            ['code' => '892', 'name' => 'Minimum flat-rate tax', 'name_fr' => 'Impot minimum forfaitaire', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/17.6.'],
            ['code' => '895', 'name' => 'Adjustment of prior-period income taxes', 'name_fr' => 'Rappel d\'impots sur resultats anterieurs', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/17.6.'],
            ['code' => '899', 'name' => 'Other income taxes', 'name_fr' => 'Autres impots sur les resultats', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 8; 02-accounting.md 2.3/17.6.'],

            // ---- Class 9 - Engagements hors bilan et comptabilite analytique ----
            // Root only: no 9-family account is in the "Verified and seeded"
            // table, so nothing below the class digit is seeded. This branch
            // is deliberately left empty for ChartOfAccountFactory to extend.
            ['code' => '9', 'name' => 'Off-balance-sheet commitments and cost accounting', 'name_fr' => 'Comptes des engagements hors bilan et de la comptabilite analytique', 'type' => 'offbalance', 'normal_balance' => 'debit', 'is_postable' => true, 'citation' => 'SYSCOHADA revise, plan comptable classe 9 (class root). No sub-account seeded: none is in the "Verified and seeded" table.'],
        ];

        $ids = [];

        // Insertion order (ascending code length) guarantees every row's
        // parent already exists - required both by CoA-2 (parent must exist)
        // and by CoA-4 (a parent must already be is_postable = false before
        // its first child is inserted; every non-leaf row above is seeded
        // with is_postable = false from the start, so there is no need for a
        // separate flip pass).
        usort($accounts, static fn (array $a, array $b): int => strlen($a['code']) <=> strlen($b['code']));

        foreach ($accounts as $account) {
            $code = $account['code'];
            $parentCode = strlen($code) > 1 ? substr($code, 0, -1) : null;

            $id = DB::table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'parent_id' => $parentCode !== null ? ($ids[$parentCode] ?? null) : null,
                'name' => $account['name'],
                'name_fr' => $account['name_fr'],
                'name_en' => null,
                'display_alias' => null,
                'type' => $account['type'],
                'normal_balance' => $account['normal_balance'],
                'is_postable' => $account['is_postable'],
                'is_system' => true,
                'is_collective' => $account['is_collective'] ?? false,
                'requires_partner' => $account['requires_partner'] ?? false,
                'allowed_partner_types' => isset($account['allowed_partner_types'])
                    ? json_encode($account['allowed_partner_types'])
                    : null,
                'requires_analytic' => false,
                'is_lettrable' => $account['is_lettrable'] ?? false,
                'is_reconcilable' => $account['is_reconcilable'] ?? false,
                'dsf_line_code' => null,
                'dsf_statement' => null,
                'default_tax_code_id' => null,
                'budget_control' => 'none',
                'currency' => 'XAF',
                'is_archived' => false,
                'opened_at' => null,
                'archived_at' => null,
                'notes' => $account['citation'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ids[$code] = $id;
        }
    }

    public function down(): void
    {
        // Children before parents: parent_id is RESTRICT, and CoA-1/CoA-2
        // triggers do not gate DELETE, but the FK itself does.
        DB::table('chart_of_accounts')
            ->orderByDesc(DB::raw('LENGTH(code)'))
            ->pluck('id')
            ->each(fn (int $id) => DB::table('chart_of_accounts')->where('id', $id)->delete());
    }
};
