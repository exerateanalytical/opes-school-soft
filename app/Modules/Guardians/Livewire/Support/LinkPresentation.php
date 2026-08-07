<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Support;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Support\Clock\BusinessDate;

/**
 * How ONE StudentGuardian link is described to a staff operator.
 *
 * Two screens render this - the student profile's Guardians tab and the
 * guardian profile's Linked Students table - and 07-students 11.3 is explicit
 * that the operator must be able to see what a guardian is entitled to. Two
 * copies of that mapping would eventually disagree about a flag, so there is
 * one.
 *
 * ── The two rows are different things, on purpose ─────────────────────────
 *
 * `flags` are the raw columns a Registrar sets (7.2). `scopes` are what the
 * guardian can actually reach TODAY, and they are resolved by asking
 * StudentGuardian::authorises() - which routes to GuardianScopeMatrix - one
 * representative capability per portal scope. 7.5: "it is transcribed into a
 * single GuardianScopeMatrix class ... Nothing else may make this decision."
 * So this class never reads a flag to infer a grant; it asks. That is why an
 * expired link or an inactive guardian shows flags but no scopes, which is
 * exactly the situation an operator most needs to see.
 */
final class LinkPresentation
{
    /**
     * One capability per portal surface of 7.5, chosen as the row that turns
     * that surface on at all.
     *
     * @var array<string, GuardianCapability>
     */
    private const SCOPE_PROBES = [
        'profile' => GuardianCapability::R02ViewChildProfileDetail,
        'results' => GuardianCapability::R05ViewReportCard,
        'fees' => GuardianCapability::R13ViewInvoices,
        'discipline' => GuardianCapability::R19ViewDisciplineList,
        'documents' => GuardianCapability::R23ViewGuardianSuppliedDocuments,
    ];

    /**
     * The scope-bearing columns of 7.2, in the table's own order.
     *
     * @var array<string, string>
     */
    private const FLAG_COLUMNS = [
        'is_primary' => 'primary',
        'has_custody' => 'custody',
        'receives_reports' => 'reports',
        'receives_invoices' => 'invoices',
        'is_emergency_contact' => 'emergency',
        'is_authorised_for_pickup' => 'pickup',
        'is_fee_payer' => 'fee_payer',
    ];

    /**
     * `current` | `pending` | `expired`, per the 7.3 predicate. A link whose
     * valid_from is in the future grants nothing and must not be shown as if
     * it did.
     */
    public static function validity(StudentGuardian $link, ?string $asOf = null): string
    {
        $date = $asOf ?? BusinessDate::today();

        if ($link->isValid($date)) {
            return 'current';
        }

        return $link->valid_from->toDateString() > $date ? 'pending' : 'expired';
    }

    /**
     * The raw flag keys that are set on this link.
     *
     * @return list<string>
     */
    public static function flags(StudentGuardian $link): array
    {
        $set = [];

        foreach (self::FLAG_COLUMNS as $column => $key) {
            if ((bool) $link->getAttribute($column) === true) {
                $set[] = $key;
            }
        }

        return $set;
    }

    /**
     * The portal scopes this link actually grants today.
     *
     * @return list<string>
     */
    public static function scopes(StudentGuardian $link, ?string $asOf = null): array
    {
        $date = $asOf ?? BusinessDate::today();
        $granted = [];

        foreach (self::SCOPE_PROBES as $key => $capability) {
            if ($link->authorises($capability->value, $date)) {
                $granted[] = $key;
            }
        }

        return $granted;
    }
}
