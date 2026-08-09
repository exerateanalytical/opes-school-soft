<?php

declare(strict_types=1);

use App\Modules\Tax\Actions\ComplianceCalendar;
use App\Modules\Tax\Domain\DueRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DeclarationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * 03-tax-procurement §7.4 / §7.6 - the compliance calendar: due dates
 * derived from due_rule DATA, T−15/7/1 alerts escalating to overdue,
 * applies_when predicates against the fiscal identity, and the seeded
 * (verified) DSF obligation.
 */
if (! function_exists('f5DeclMonthlyObligation')) {
    /** A monthly obligation row for a fresh type, due day 15 of the next month. */
    function f5DeclMonthlyObligation(string $typeCode = 'tva_monthly', ?array $appliesWhen = null): void
    {
        $typeId = f5DeclType($typeCode);

        DB::table('tax_obligations')->insert([
            'tax_declaration_type_id' => $typeId,
            'frequency' => 'monthly',
            'due_rule' => 'day_of_next_month(15)',
            'applies_when' => $appliesWhen !== null ? json_encode($appliesWhen) : null,
            'penalty_note' => null,
            'legal_ref' => null,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('parses the two due_rule forms and rejects anything else', function () {
    expect(DueRule::parse('day_of_next_month(15)')->dueDateFor(2031, 3, null)->toDateString())
        ->toBe('2031-04-15');

    $centreRule = DueRule::parse('tax_centre_dependent(DGE=03-15,CIME=04-15,other=05-15)');
    expect($centreRule->dueDateFor(2031, 0, \App\Modules\Tax\Domain\TaxCentreType::Dge)->toDateString())->toBe('2032-03-15')
        ->and($centreRule->dueDateFor(2031, 0, \App\Modules\Tax\Domain\TaxCentreType::Cime)->toDateString())->toBe('2032-04-15')
        // §7.6: CDI, CSI and anything else take the `other` arm.
        ->and($centreRule->dueDateFor(2031, 0, \App\Modules\Tax\Domain\TaxCentreType::Csi)->toDateString())->toBe('2032-05-15');

    expect(fn () => DueRule::parse('every_full_moon()'))
        ->toThrow(DomainException::class, 'Unknown due_rule');
});

it('surfaces the seeded DSF obligation with the centre-dependent due date', function () {
    // The DSF row is THE one seeded obligation (§7.5 verified); its
    // applies_when is {"tax_regime":"reel"}, which the confirmed identity
    // satisfies.
    f5DeclConfirmedIdentity(['tax_centre_type' => 'DGE', 'tax_regime' => 'reel']);

    $calendar = app(ComplianceCalendar::class)->handle('2032-03-01');

    $dsf = array_values(array_filter($calendar['items'], fn (array $item): bool => $item['declaration_type'] === 'dsf_annual' && $item['period_year'] === 2031));

    expect($dsf)->toHaveCount(1)
        ->and($dsf[0]['due_date'])->toBe('2032-03-15')
        ->and($dsf[0]['alert_level'])->toBe('t-15')
        ->and($dsf[0]['is_filed'])->toBeFalse()
        ->and((string) $dsf[0]['penalty_note'])->toContain('25%');
});

it('escalates the alert level as the due date approaches and passes', function () {
    f5DeclConfirmedIdentity(['tax_centre_type' => 'DGE', 'tax_regime' => 'reel']);

    $levelOn = function (string $today): string {
        $items = app(ComplianceCalendar::class)->handle($today)['items'];
        $dsf = array_values(array_filter($items, fn (array $item): bool => $item['declaration_type'] === 'dsf_annual' && $item['period_year'] === 2031));

        return $dsf[0]['alert_level'];
    };

    expect($levelOn('2032-02-01'))->toBe('upcoming')
        ->and($levelOn('2032-03-01'))->toBe('t-15')
        ->and($levelOn('2032-03-10'))->toBe('t-7')
        ->and($levelOn('2032-03-14'))->toBe('t-1')
        ->and($levelOn('2032-03-15'))->toBe('due_today')
        // §7.4: an unfiled obligation past its date is OVERDUE, visibly.
        ->and($levelOn('2032-03-20'))->toBe('overdue');
});

it('skips predicate obligations with a visible note while the identity is unconfirmed', function () {
    // Never silently assume an obligation applies or not (00-core §16).
    $calendar = app(ComplianceCalendar::class)->handle('2032-03-01');

    $dsf = array_filter($calendar['items'], fn (array $item): bool => $item['declaration_type'] === 'dsf_annual');

    expect($dsf)->toBe([])
        ->and(implode(' ', $calendar['notes']))->toContain('dsf_annual');
});

it('excludes obligations whose predicate the identity fails', function () {
    f5DeclConfirmedIdentity(['tax_regime' => 'liberatoire', 'is_tva_registered' => false]);
    f5DeclMonthlyObligation('tva_monthly', ['is_tva_registered' => true]);

    $items = app(ComplianceCalendar::class)->handle('2031-04-10')['items'];

    // Not TVA-registered → no TVA obligation; the seeded DSF also drops
    // (regime is not réel).
    expect($items)->toBe([]);
});

it('derives monthly occurrences from day_of_next_month and marks filed periods', function () {
    f5DeclConfirmedIdentity(['tax_regime' => 'reel', 'is_tva_registered' => true]);
    f5DeclMonthlyObligation('tva_monthly', ['is_tva_registered' => true]);

    $items = app(ComplianceCalendar::class)->handle('2031-04-10')['items'];
    $march = array_values(array_filter($items, fn (array $item): bool => $item['declaration_type'] === 'tva_monthly' && $item['period_month'] === 3));

    // March 2031 is due 15 April 2031: five days out → T−7 band.
    expect($march)->toHaveCount(1)
        ->and($march[0]['due_date'])->toBe('2031-04-15')
        ->and($march[0]['alert_level'])->toBe('t-7')
        ->and($march[0]['is_filed'])->toBeFalse();

    // Once the declaration is filed, the row reports it.
    $calendar = f5DeclCalendar('2031-03-15');
    DB::table('tax_declarations')->insert([
        'declaration_type' => 'tva_monthly',
        'period_type' => 'month',
        'period_year' => 2031,
        'period_month' => 3,
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'status' => 'filed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $items = app(ComplianceCalendar::class)->handle('2031-04-10')['items'];
    $march = array_values(array_filter($items, fn (array $item): bool => $item['declaration_type'] === 'tva_monthly' && $item['period_month'] === 3));

    expect($march[0]['alert_level'])->toBe('filed')
        ->and($march[0]['is_filed'])->toBeTrue();
});
