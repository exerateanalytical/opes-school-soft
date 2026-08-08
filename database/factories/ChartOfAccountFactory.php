<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\NormalBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds throwaway leaf accounts nested under the seeded class-9 root
 * ("Comptes des engagements hors bilan et de la comptabilite analytique").
 * Class 9 ships with only its root seeded (2026_08_07_230002 - no explicit
 * 9-family account is in the "Verified and seeded" table), so it is the one
 * branch of the tree a test can extend without colliding with statutory
 * data or guessing at a real SYSCOHADA sub-code.
 *
 * Every intermediate level is created idempotently and flipped
 * `is_postable = false` before its first child is inserted - mirroring
 * exactly what CreateAccount must do, and what the CoA-4 trigger demands of
 * any writer.
 *
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    /** @var class-string<ChartOfAccount> */
    protected $model = ChartOfAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // CoA-2 requires depth to increase by EXACTLY one digit per level -
        // no skipping - so a unique 5-digit suffix becomes a 4-level
        // intermediate chain (one digit each) plus one final leaf digit,
        // not a single 5-digit jump from the root.
        $suffix = str_pad((string) fake()->unique()->numberBetween(0, 99999), 5, '0', STR_PAD_LEFT);

        $branch = $this->ensureChild(null, '9', AccountType::Analytic, NormalBalance::Debit);
        $code = '9';
        for ($i = 0; $i < 4; $i++) {
            $code .= $suffix[$i];
            $branch = $this->ensureChild($branch, $code, AccountType::Analytic, NormalBalance::Debit);
        }

        // The leaf itself is inserted by the factory framework's own
        // create() call, not by ensureChild() - so its parent must be
        // flipped non-postable here, mirroring what ensureChild does for
        // every other level.
        if ($branch->is_postable) {
            $branch->forceFill(['is_postable' => false])->save();
        }

        return [
            'code' => $code.$suffix[4],
            'parent_id' => $branch->id,
            'name' => 'Test analytic leaf',
            'name_fr' => 'Compte analytique de test',
            'name_en' => null,
            'display_alias' => null,
            'type' => AccountType::Analytic,
            'normal_balance' => NormalBalance::Debit,
            'is_postable' => true,
            'is_system' => false,
            'is_collective' => false,
            'requires_partner' => false,
            'allowed_partner_types' => null,
            'requires_analytic' => false,
            'is_lettrable' => false,
            'is_reconcilable' => false,
            'dsf_line_code' => null,
            'dsf_statement' => null,
            'default_tax_code_id' => null,
            'budget_control' => 'none',
            'currency' => 'XAF',
            'is_archived' => false,
            'opened_at' => null,
            'archived_at' => null,
            'notes' => null,
        ];
    }

    private function ensureChild(
        ?ChartOfAccount $parent,
        string $code,
        AccountType $type,
        NormalBalance $normalBalance,
    ): ChartOfAccount {
        $existing = ChartOfAccount::query()->where('code', $code)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($parent !== null && $parent->is_postable) {
            $parent->forceFill(['is_postable' => false])->save();
        }

        return ChartOfAccount::query()->create([
            'code' => $code,
            'parent_id' => $parent?->id,
            'name' => 'Test scaffold '.$code,
            'name_fr' => 'Compte technique de test '.$code,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_postable' => true,
            'is_system' => false,
            'currency' => 'XAF',
        ]);
    }
}
