<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The four books AUDCIF Art. 19 makes mandatory (02-accounting §14).
 *
 * These are legal registers, not reports. v1 omitted the livre d'inventaire
 * entirely and treated the other three as reports, which is the distinction
 * this enum exists to hold: once generated a book is immutable, and a
 * correction produces a NEW book that supersedes its predecessor rather than
 * overwriting it.
 */
enum StatutoryBookType: string
{
    case LivreJournal = 'livre_journal';
    case GrandLivre = 'grand_livre';
    case BalanceGenerale = 'balance_generale';
    case LivreInventaire = 'livre_inventaire';

    public function label(): string
    {
        return match ($this) {
            self::LivreJournal => 'Livre-journal',
            self::GrandLivre => 'Grand livre',
            self::BalanceGenerale => 'Balance generale',
            self::LivreInventaire => "Livre d'inventaire",
        };
    }

    /**
     * The livre d'inventaire is generated once per fiscal year at close,
     * after the §17 year-end sequence completes (§14.2). The other three may
     * be generated for any period inside a year.
     */
    public function isAnnualOnly(): bool
    {
        return $this === self::LivreInventaire;
    }
}
