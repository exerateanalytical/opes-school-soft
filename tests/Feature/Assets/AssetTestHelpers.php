<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Assets\Actions\RegisterAsset;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Factories\JournalEntryFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 9 F1 asset-register suites. Prefix
 * phase9Asset, every helper function_exists-guarded (00-core test
 * discipline; names must never collide with another agent's).
 */
if (! function_exists('phase9AssetUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function phase9AssetUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('phase9AssetActor')) {
    function phase9AssetActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('phase9AssetAccountId')) {
    /** A seeded chart account id by exact code. */
    function phase9AssetAccountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}

if (! function_exists('phase9AssetCalendar')) {
    /**
     * Fiscal + academic year with an open period covering the date.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function phase9AssetCalendar(string $date): array
    {
        return (new JournalEntryFactory)->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('phase9AssetSupplierId')) {
    /** A minimal supplier row (the 481 partner side of a capitalisation). */
    function phase9AssetSupplierId(): int
    {
        $creator = User::factory()->create();

        return (int) DB::table('suppliers')->insertGetId([
            'code' => 'P9S'.fake()->unique()->numberBetween(1, 999_999),
            'name' => 'Equipements Scolaires '.fake()->unique()->numberBetween(1, 999_999),
            'supplier_type' => 'company',
            'payable_account_id' => phase9AssetAccountId('401'),
            'created_by' => $creator->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('phase9AssetPostingRules')) {
    /**
     * The two F1 posting rules, saved through the REAL SavePostingRule
     * gate against the §11.2 catalogue events:
     *
     *  - asset.acquired:     Dr the payload's target class-2 account for
     *    the cost, balancing Cr 4812 Fournisseurs d'investissements with
     *    the supplier partner (§4.4 - capex credits 481, never 401);
     *  - asset.commissioned: Dr the gross asset account, balancing Cr 249
     *    (assets under construction) - the §3 transfer.
     */
    function phase9AssetPostingRules(): void
    {
        $accountant = phase9AssetUser(Permission::LedgerConfigure->value);
        $journal = Journal::factory()->create();
        $save = app(SavePostingRule::class);

        $save->handle([
            'code' => 'p9f1_asset_acquired',
            'event' => PostingEvent::AssetAcquired->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Acquisition immobilisation {asset.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.asset_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'asset.cost',
                'label_expression' => 'Immobilisation {asset.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '4812',
                'sign' => LineSign::Credit,
                'amount_expression' => 'asset.cost',
                'is_balancing' => true,
                'partner_source' => 'asset.partner',
                'label_expression' => 'Fournisseur investissements {asset.reference}',
            ],
        ], phase9AssetActor($accountant));

        $save->handle([
            'code' => 'p9f1_asset_commissioned',
            'event' => PostingEvent::AssetCommissioned->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Mise en service {asset.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.asset_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'asset.cost',
                'label_expression' => 'Immobilisation {asset.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '249',
                'sign' => LineSign::Credit,
                'amount_expression' => 'asset.cost',
                'is_balancing' => true,
                'label_expression' => 'Transfert immobilisations en cours',
            ],
        ], phase9AssetActor($accountant));
    }
}

if (! function_exists('phase9AssetCategory')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function phase9AssetCategory(array $overrides = []): AssetCategory
    {
        return AssetCategory::factory()->create($overrides);
    }
}

if (! function_exists('phase9AssetBaseline')) {
    /**
     * Posting rules + calendar + category + manager, the standing start
     * for a capitalisation test. Dates all live inside the open period.
     *
     * @param  array<string, mixed>  $categoryOverrides
     * @return array{user: User, actor: Actor, category: AssetCategory, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, date: string, supplier_id: int}
     */
    function phase9AssetBaseline(string $date = '2431-03-10', array $categoryOverrides = []): array
    {
        phase9AssetPostingRules();

        $calendar = phase9AssetCalendar($date);
        $category = phase9AssetCategory($categoryOverrides);
        $supplierId = phase9AssetSupplierId();
        // MANAGE drives the Assets doors; LedgerPost is demanded by the
        // posting engine itself (Draft/PostJournalEntry) when a
        // capitalisation posts through PostFromEvent.
        $user = phase9AssetUser(AssetPermission::MANAGE, Permission::LedgerPost->value);

        return [
            'user' => $user,
            'actor' => phase9AssetActor($user),
            'category' => $category,
            'calendar' => $calendar,
            'date' => $date,
            'supplier_id' => $supplierId,
        ];
    }
}

if (! function_exists('phase9AssetRegister')) {
    /**
     * A DRAFT asset through the real RegisterAsset gate.
     *
     * @param  array{user: User, actor: Actor, category: AssetCategory, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, date: string, supplier_id: int}  $baseline
     * @param  array<string, mixed>  $overrides
     */
    function phase9AssetRegister(array $baseline, array $overrides = []): Asset
    {
        return app(RegisterAsset::class)->handle([
            'asset_category_id' => (int) $baseline['category']->getKey(),
            'name' => 'Minibus '.fake()->unique()->numberBetween(1, 999_999),
            'acquisition_date' => $baseline['date'],
            'acquisition_cost' => 35_775_000,
            'cost_basis' => 'ttc_non_recoverable_vat_capitalised',
            'non_recoverable_vat_amount' => 5_775_000,
            'acquisition_type' => 'purchase',
            'supplier_id' => $baseline['supplier_id'],
            'fiscal_year_id' => $baseline['calendar']['fiscal_year_id'],
            'academic_year_id' => $baseline['calendar']['academic_year_id'],
            ...$overrides,
        ], $baseline['actor']);
    }
}

if (! function_exists('phase9AssetEntryLines')) {
    /**
     * The journal-entry lines of an entry, joined to their account codes.
     *
     * @return list<object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}>
     */
    function phase9AssetEntryLines(int $journalEntryId): array
    {
        /** @var list<object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}> $rows */
        $rows = DB::table('journal_entry_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.journal_entry_id', $journalEntryId)
            ->orderBy('journal_entry_lines.id')
            ->get([
                'chart_of_accounts.code',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.partner_type',
                'journal_entry_lines.partner_id',
            ])
            ->map(static fn (object $row): object => (object) [
                'code' => (string) $row->code,
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                'partner_type' => $row->partner_type === null ? null : (string) $row->partner_type,
                'partner_id' => $row->partner_id === null ? null : (int) $row->partner_id,
            ])
            ->values()
            ->all();

        return $rows;
    }
}

if (! function_exists('phase9AssetStaffId')) {
    function phase9AssetStaffId(): int
    {
        return (int) \App\Modules\HR\Models\StaffMember::factory()->create()->getKey();
    }
}
