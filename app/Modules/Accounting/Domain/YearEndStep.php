<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §17.2 - the thirteen-step close sequence, in
 * order, as the enum the checklist is generated from. §17.1: "Each step is
 * a `YearEndChecklistItem` with its own sign-off."
 *
 * `isAutomated()` marks the §17.3 YE-4 steps whose status is decided by a
 * validation Action rather than by a human ticking a box - the trial
 * balance (§17.9), and the three steps that ARE ledger writes (closing,
 * appropriation, à-nouveaux): those complete when, and only when, the entry
 * they are responsible for exists. A human may still WAIVE an automated
 * item, with a reason, on record - that is the escape hatch §17.3 designs,
 * and it is the only one.
 *
 * The step ORDER is the invariant YE-3 reads. Nothing here may be
 * reordered without reordering §17.2.
 */
enum YearEndStep: string
{
    case SoftLockPeriods = 'soft_lock_periods';
    case PhysicalInventory = 'physical_inventory';
    case CutOffEntries = 'cut_off_entries';
    case DoubtfulDebtReview = 'doubtful_debt_review';
    case Depreciation = 'depreciation';
    case Provisions = 'provisions';
    case TrialBalance = 'trial_balance';
    case TaxProvision = 'tax_provision';
    case ClosingEntry = 'closing_entry';
    case ResultAppropriation = 'result_appropriation';
    case OpeningBalances = 'opening_balances';
    case StatutoryBooks = 'statutory_books';
    case HardLock = 'hard_lock';

    /** §17.2's step number, and therefore the YE-3 completion order. */
    public function sequence(): int
    {
        return match ($this) {
            self::SoftLockPeriods => 1,
            self::PhysicalInventory => 2,
            self::CutOffEntries => 3,
            self::DoubtfulDebtReview => 4,
            self::Depreciation => 5,
            self::Provisions => 6,
            self::TrialBalance => 7,
            self::TaxProvision => 8,
            self::ClosingEntry => 9,
            self::ResultAppropriation => 10,
            self::OpeningBalances => 11,
            self::StatutoryBooks => 12,
            self::HardLock => 13,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::SoftLockPeriods => 'Soft-lock all periods of the fiscal year',
            self::PhysicalInventory => 'Physical inventory and fixed-asset verification',
            self::CutOffEntries => 'Cut-off entries and revenue deferral',
            self::DoubtfulDebtReview => 'Doubtful-debt review and provisions',
            self::Depreciation => 'Final depreciation run',
            self::Provisions => 'Provisions (leave, litigation, other)',
            self::TrialBalance => 'Trial-balance validation',
            self::TaxProvision => 'Income-tax provision (compte 89)',
            self::ClosingEntry => 'Closing entry - classes 6, 7, 8 to compte 13',
            self::ResultAppropriation => 'Result appropriation',
            self::OpeningBalances => 'A-nouveaux into the next exercice',
            self::StatutoryBooks => 'Livre d\'inventaire and the statutory books',
            self::HardLock => 'Hard-lock the fiscal year',
        };
    }

    public function titleFr(): string
    {
        return match ($this) {
            self::SoftLockPeriods => 'Verrouillage souple de toutes les periodes',
            self::PhysicalInventory => 'Inventaire physique et verification des immobilisations',
            self::CutOffEntries => 'Ecritures de cut-off et produits constates d\'avance',
            self::DoubtfulDebtReview => 'Revue des creances douteuses et depreciations',
            self::Depreciation => 'Dotation aux amortissements de cloture',
            self::Provisions => 'Provisions (conges, litiges, autres)',
            self::TrialBalance => 'Validation de la balance generale',
            self::TaxProvision => 'Provision pour impot sur le resultat (compte 89)',
            self::ClosingEntry => 'Ecriture de cloture - classes 6, 7, 8 au compte 13',
            self::ResultAppropriation => 'Affectation du resultat',
            self::OpeningBalances => 'A-nouveaux dans l\'exercice suivant',
            self::StatutoryBooks => 'Livre d\'inventaire et livres obligatoires',
            self::HardLock => 'Verrouillage definitif de l\'exercice',
        };
    }

    /** §17.3 YE-4. */
    public function isAutomated(): bool
    {
        return match ($this) {
            self::TrialBalance,
            self::ClosingEntry,
            self::ResultAppropriation,
            self::OpeningBalances => true,
            default => false,
        };
    }

    /**
     * Every step of §17.2 is mandatory. A school that genuinely did not do
     * one records a WAIVER with a reason (YE-2) - which is a different, and
     * auditable, statement from "this step did not apply".
     */
    public function isMandatory(): bool
    {
        return true;
    }

    /** @return list<self> in §17.2 order. */
    public static function ordered(): array
    {
        $steps = self::cases();

        usort($steps, static fn (self $a, self $b): int => $a->sequence() <=> $b->sequence());

        return $steps;
    }
}
