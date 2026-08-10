<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\CreateAccount;
use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\NormalBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo treasury: the four places a Cameroonian school's money actually sits
 * (02-accounting.md §2, §11.3) - the cash box, the bank account, and the MTN
 * and Orange mobile-money floats - opened as class-5 SUB-accounts under the
 * chart's existing system parents, plus a backfill that tells the ~57
 * existing demo receipts which of those four they landed in.
 *
 * Why sub-accounts rather than a parallel "treasury accounts" table: in
 * SYSCOHADA the treasury account IS the class-5 ledger account, which is
 * exactly what `supplier_payments.treasury_account_id` (Phase 5) and now
 * `payments.treasury_account_id` point at. Opening 5521 and 5522 under 552 is
 * how a school separates MTN from Orange; a second table would fragment the
 * model and leave the two floats unreconcilable against their own operator
 * statements - the very defect §11.3 documents.
 *
 * Same idempotency strategy as DemoDataSeeder: firstOrCreate / exists guards
 * at every insertion point, no single outer transaction, safe to re-run.
 *
 * The backfill writes ONLY `treasury_account_id`. No amount changes, no
 * ledger entry is created, amended or reversed - the money already posted is
 * the money that posted. It goes through the query builder rather than the
 * Payment model deliberately: a payment is immutable after insert (04-fees
 * A3) and the model observer would - correctly - refuse. Naming, after the
 * fact, the float a historical receipt landed in is a demo-data statement
 * about rows this seeder owns, not an edit to a school's books.
 */
final class TreasurySeeder extends Seeder
{
    /**
     * The four floats, as a LIST rather than a code-keyed map: PHP silently
     * casts numeric-string array keys to int, and an account code is a
     * string in this chart.
     *
     * `is_reconcilable` is true for the bank and both MoMo floats: those are
     * the ones with an external statement to reconcile against. A cash box
     * is counted, not reconciled.
     *
     * @var list<array{code: string, parent: string, name: string, name_fr: string, reconcilable: bool}>
     */
    private const TREASURY_ACCOUNTS = [
        ['code' => '571', 'parent' => '57', 'name' => 'Main Cash Box', 'name_fr' => 'Caisse principale', 'reconcilable' => false],
        ['code' => '521', 'parent' => '52', 'name' => 'Main Bank Account', 'name_fr' => 'Banque principale', 'reconcilable' => true],
        ['code' => '5521', 'parent' => '552', 'name' => 'MTN Mobile Money', 'name_fr' => 'MTN Mobile Money', 'reconcilable' => true],
        ['code' => '5522', 'parent' => '552', 'name' => 'Orange Money', 'name_fr' => 'Orange Money', 'reconcilable' => true],
    ];

    /**
     * How the historical demo receipts spread across the four floats, keyed
     * by `payments.id % 10` - a deterministic, re-runnable split that is
     * plausible for a Cameroonian school: cash still dominates the counter,
     * MoMo is the fast-growing second, the bank takes the large transfers.
     *
     * @var array<int, string>
     */
    private const BACKFILL_SPREAD = [
        0 => '571', 1 => '571', 2 => '571', 3 => '571',
        4 => '5521', 5 => '5521', 6 => '5521',
        7 => '5522', 8 => '5522',
        9 => '521',
    ];

    public function run(): void
    {
        Auth::login($this->admin());

        $accountIds = $this->createTreasuryAccounts();
        $backfilled = $this->backfillPayments($accountIds);

        $this->command?->info(sprintf(
            'Treasury seeding complete: %d class-5 accounts present, %d payments attributed.',
            count($accountIds),
            $backfilled,
        ));
    }

    /**
     * @return array<string, int> code => chart_of_accounts.id
     */
    private function createTreasuryAccounts(): array
    {
        $create = app(CreateAccount::class);
        $ids = [];

        foreach (self::TREASURY_ACCOUNTS as $definition) {
            $code = $definition['code'];

            /** @var ChartOfAccount|null $existing */
            $existing = ChartOfAccount::query()->where('code', $code)->first();

            if ($existing !== null) {
                $ids[$code] = (int) $existing->getKey();

                continue;
            }

            $parentId = ChartOfAccount::query()->where('code', $definition['parent'])->value('id');

            if ($parentId === null) {
                $this->command?->warn("Parent account {$definition['parent']} is missing; skipping {$code}.");

                continue;
            }

            // The sanctioned path: CreateAccount enforces CoA-2 (strict code
            // prefix, one digit deeper) and performs the CoA-4 flip of the
            // parent to non-postable under its own lock.
            $account = $create->handle(
                parentId: (int) $parentId,
                code: $code,
                name: $definition['name'],
                nameFr: $definition['name_fr'],
                type: AccountType::Asset,
                normalBalance: NormalBalance::Debit,
                isReconcilable: $definition['reconcilable'],
                notes: 'Treasury float (02-accounting 11.3).',
                actor: Actor::system(),
            );

            $ids[$code] = (int) $account->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $accountIds
     * @return int rows attributed
     */
    private function backfillPayments(array $accountIds): int
    {
        $rows = DB::table('payments')
            ->whereNull('treasury_account_id')
            ->orderBy('id')
            ->pluck('id');

        $updated = 0;

        foreach ($rows as $id) {
            $code = self::BACKFILL_SPREAD[((int) $id) % 10] ?? '571';
            $accountId = $accountIds[$code] ?? null;

            if ($accountId === null) {
                continue;
            }

            $updated += DB::table('payments')
                ->where('id', $id)
                ->whereNull('treasury_account_id')
                ->update(['treasury_account_id' => $accountId]);
        }

        return $updated;
    }

    /**
     * CreateAccount is Gate-guarded (`accounting.manage`), so the seeder acts
     * as the same demo administrator DemoDataSeeder uses rather than
     * bypassing the authorization it is meant to exercise.
     */
    private function admin(): User
    {
        (new RolePermissionSeeder())->run();

        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['email' => 'demo.admin@opeschool.test'],
            ['name' => 'Demo Admin', 'password' => Str::random(32)],
        );

        if (! $user->hasRole(Role::SuperAdmin->value)) {
            $user->assignRole(Role::SuperAdmin->value);
        }

        // `accounting.manage` has no case in Identity's Permission enum yet
        // (see CreateAccount::PERMISSION's docblock) and so is not part of
        // RolePermissionSeeder's role map. Granted here to the demo admin
        // only - exactly as ChartOfAccountTest's own helper does - rather
        // than editing Identity's enum or bypassing the Gate the Action is
        // meant to exercise.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        SpatiePermission::findOrCreate(CreateAccount::PERMISSION, 'web');

        if (! $user->hasPermissionTo(CreateAccount::PERMISSION)) {
            $user->givePermissionTo(CreateAccount::PERMISSION);
        }

        return $user->fresh() ?? $user;
    }
}
