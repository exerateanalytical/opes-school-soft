<?php

declare(strict_types=1);

// GuardianScopeMatrix - the pure transcription of docs/specs/07-students.md
// 7.5, exercised cell by cell (docs/plans/phase-12-13.md, Phase 12 test list).
//
// No database, no application state: the matrix is a pure function of
// (flags, capability), so this suite enumerates its ENTIRE input space -
// every capability x every combination of the five link flags, under the
// passing conjunctive gate - and checks each answer against an INDEPENDENT
// transcription of the spec table kept in this file. The two transcriptions
// were written from the spec separately; a cell widened or narrowed in either
// place fails loudly, in both directions (grant AND deny), which is exactly
// what 7.5 demands of its truth table.

use App\Modules\Guardians\Domain\GuardianAuthorizationFlags;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Domain\GuardianScopeMatrix;

/** The five link flags, spec order (07-students 7.5 column order). */
const P12G_FLAGS = [
    'has_custody',
    'receives_reports',
    'receives_invoices',
    'is_fee_payer',
    'is_emergency_contact',
];

if (! function_exists('p12gFlags')) {
    /**
     * @param  list<string>  $on  flags set true; everything else false
     */
    function p12gFlags(array $on = [], bool $valid = true, bool $active = true): GuardianAuthorizationFlags
    {
        return new GuardianAuthorizationFlags(
            isValid: $valid,
            guardianIsActive: $active,
            hasCustody: in_array('has_custody', $on, true),
            receivesReports: in_array('receives_reports', $on, true),
            receivesInvoices: in_array('receives_invoices', $on, true),
            isFeePayer: in_array('is_fee_payer', $on, true),
            isEmergencyContact: in_array('is_emergency_contact', $on, true),
        );
    }
}

if (! function_exists('p12gAllFlagCombinations')) {
    /**
     * All 2^5 = 32 subsets of the five flags.
     *
     * @return list<list<string>>
     */
    function p12gAllFlagCombinations(): array
    {
        $combos = [];

        for ($mask = 0; $mask < 32; $mask++) {
            $on = [];

            foreach (P12G_FLAGS as $i => $flag) {
                if (($mask >> $i) & 1) {
                    $on[] = $flag;
                }
            }

            $combos[] = $on;
        }

        return $combos;
    }
}

if (! function_exists('p12gGrantRules')) {
    /**
     * The independent transcription of 7.5's Grant rule column, one entry
     * per row: 'any' = any valid link; 'nobody' = denied always; a list of
     * flags = granted iff AT LEAST ONE listed flag is set (every multi-flag
     * rule in the table is an OR; the AND-shaped daggers - publication state,
     * promotion applied, case visibility - are conjuncts owned by other
     * modules' data and applied by the calling query, per the spec's own
     * reading rules).
     *
     * @return array<string, string|list<string>> keyed by capability name
     */
    function p12gGrantRules(): array
    {
        return [
            'R01ViewChildIdentity' => 'any',
            'R02ViewChildProfileDetail' => ['has_custody'],
            'R03ViewChildEmergencyMedical' => ['has_custody', 'is_emergency_contact'],
            'R04ViewChildFullMedical' => ['has_custody'],
            'R05ViewReportCard' => ['receives_reports'],
            'R06DownloadReportCard' => ['receives_reports'],
            'R07ViewPublishedMarks' => ['receives_reports'],
            'R08ViewUnpublishedMarks' => 'nobody',
            'R09ViewRankAndClassMean' => ['receives_reports'],
            'R10ViewAnnualAverageAndPromotion' => ['receives_reports'],
            'R11ViewAttendanceSummary' => ['has_custody', 'receives_reports'],
            'R12ViewAttendanceDetail' => ['has_custody', 'receives_reports'],
            'R13ViewInvoices' => ['receives_invoices', 'is_fee_payer'],
            'R14ViewFeeStatement' => ['receives_invoices', 'is_fee_payer'],
            'R15ViewReceipts' => ['receives_invoices', 'is_fee_payer'],
            'R16ViewOwnPayments' => 'any',
            'R17ViewOtherGuardianPayments' => ['receives_invoices', 'is_fee_payer'],
            'R18InitiatePayment' => ['is_fee_payer'],
            'R19ViewDisciplineList' => ['has_custody'],
            'R20ViewDisciplineNarrative' => ['has_custody'],
            'R21AcknowledgeSanction' => ['has_custody'],
            'R22ViewSchoolIssuedDocuments' => ['has_custody', 'receives_reports'],
            'R23ViewGuardianSuppliedDocuments' => ['has_custody'],
            'R24UploadDocument' => ['has_custody'],
            'R25DeleteDocument' => 'nobody',
            'R26ViewTimetableAndAnnouncements' => 'any',
            'R27RequestGuardianMeeting' => ['has_custody'],
            'R28EditChildRecord' => 'nobody',
            'R29EditOwnContactDetails' => 'any',
            'R30EditAuthorizationFlag' => 'nobody',
            'R31ViewOtherGuardiansOfChild' => ['has_custody'],
            'R32AnythingForAnUnlinkedChild' => 'nobody',
        ];
    }
}

it('has exactly one capability per row of 7.5, rows 1 through 32, and a rule for each', function () {
    $rules = p12gGrantRules();
    $rows = [];

    foreach (GuardianCapability::cases() as $capability) {
        expect($rules)->toHaveKey($capability->name);
        $rows[] = $capability->matrixRow();
    }

    sort($rows);

    expect(count(GuardianCapability::cases()))->toBe(32)
        ->and(count($rules))->toBe(32)
        ->and($rows)->toBe(range(1, 32));
});

it('transcribes every cell of the 7.5 matrix: 32 capabilities x 32 flag combinations, grant AND deny', function () {
    $rules = p12gGrantRules();

    foreach (GuardianCapability::cases() as $capability) {
        $rule = $rules[$capability->name];

        foreach (p12gAllFlagCombinations() as $on) {
            // A list means OR-of-flags; the two string sentinels mean
            // always ('any') and never ('nobody').
            $expected = is_array($rule)
                ? array_intersect($rule, $on) !== []
                : $rule === 'any';

            $actual = GuardianScopeMatrix::allows(p12gFlags($on), $capability);

            expect($actual)->toBe(
                $expected,
                sprintf(
                    'Row %d (%s) with flags [%s]: matrix said %s, spec says %s.',
                    $capability->matrixRow(),
                    $capability->name,
                    implode(', ', $on),
                    var_export($actual, true),
                    var_export($expected, true),
                ),
            );
        }
    }
});

it('denies every capability, under every flag combination, when the link is not valid', function () {
    // 7.5 reading rules: a guardian whose link has expired retains NO portal
    // access, including to periods when the link was valid. Row 16's
    // own-payments exception is a property of the payment query
    // (payer_guardian_id), not of link scope - the matrix header records why.
    foreach (GuardianCapability::cases() as $capability) {
        foreach (p12gAllFlagCombinations() as $on) {
            expect(GuardianScopeMatrix::allows(p12gFlags($on, valid: false), $capability))
                ->toBeFalse("Row {$capability->matrixRow()} granted on an invalid link.");
        }
    }
});

it('denies every capability, under every flag combination, when the guardian is not active', function () {
    // 7.5 conjunctive condition (b): Guardian.status = 'active'. Deactivating
    // the guardian closes the portal across all children without touching a
    // single link row.
    foreach (GuardianCapability::cases() as $capability) {
        foreach (p12gAllFlagCombinations() as $on) {
            expect(GuardianScopeMatrix::allows(p12gFlags($on, active: false), $capability))
                ->toBeFalse("Row {$capability->matrixRow()} granted for an inactive guardian.");
        }
    }
});

it('checks publication state first: unpublished marks are denied to everybody (row 8)', function () {
    // Even the fullest possible link - every flag on, valid, active - sees
    // nothing unpublished. The matrix returns false UNCONDITIONALLY for this
    // capability, so a caller who forgets the publication check still cannot
    // serve an unpublished mark.
    expect(GuardianScopeMatrix::allows(p12gFlags(P12G_FLAGS), GuardianCapability::R08ViewUnpublishedMarks))
        ->toBeFalse();

    foreach (p12gAllFlagCombinations() as $on) {
        expect(GuardianScopeMatrix::allows(p12gFlags($on), GuardianCapability::R08ViewUnpublishedMarks))
            ->toBeFalse();
    }
});

it('grants payments-made-by-me on ANY valid link, even one with every flag off (row 16)', function () {
    // Row 16: "any valid link - it is their own transaction". The
    // emergency-contact-only sponsor with no custody, no reports, no
    // invoices still sees the payments THEY made.
    expect(GuardianScopeMatrix::allows(p12gFlags([]), GuardianCapability::R16ViewOwnPayments))
        ->toBeTrue();

    foreach (P12G_FLAGS as $flag) {
        expect(GuardianScopeMatrix::allows(p12gFlags([$flag]), GuardianCapability::R16ViewOwnPayments))
            ->toBeTrue();
    }

    // ...but never on an expired link: portal access to the child is gone,
    // and their own records are the payment query's business.
    expect(GuardianScopeMatrix::allows(p12gFlags([], valid: false), GuardianCapability::R16ViewOwnPayments))
        ->toBeFalse();
});

it('takes no is_primary input at all: the flag that grants nothing cannot even be expressed', function () {
    // 7.5: "is_primary grants nothing on its own". The strongest enforcement
    // is structural - GuardianAuthorizationFlags simply has no such property,
    // so no future cell can quietly consult it.
    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(GuardianAuthorizationFlags::class))->getProperties(),
    );

    expect($properties)->not->toContain('isPrimary')
        ->and($properties)->toBe([
            'isValid',
            'guardianIsActive',
            'hasCustody',
            'receivesReports',
            'receivesInvoices',
            'isFeePayer',
            'isEmergencyContact',
        ]);
});

it('is the only place that decides: fee capabilities never leak to reports-only links and vice versa', function () {
    // Two spot checks the exhaustive sweep already covers, restated here in
    // the shape a reviewer reads first: the classic cross-scope leaks.
    $reportsOnly = p12gFlags(['receives_reports']);
    $feesOnly = p12gFlags(['is_fee_payer']);

    expect(GuardianScopeMatrix::allows($reportsOnly, GuardianCapability::R13ViewInvoices))->toBeFalse()
        ->and(GuardianScopeMatrix::allows($reportsOnly, GuardianCapability::R18InitiatePayment))->toBeFalse()
        ->and(GuardianScopeMatrix::allows($feesOnly, GuardianCapability::R05ViewReportCard))->toBeFalse()
        ->and(GuardianScopeMatrix::allows($feesOnly, GuardianCapability::R04ViewChildFullMedical))->toBeFalse()
        ->and(GuardianScopeMatrix::allows($feesOnly, GuardianCapability::R18InitiatePayment))->toBeTrue()
        ->and(GuardianScopeMatrix::allows($reportsOnly, GuardianCapability::R05ViewReportCard))->toBeTrue();
});
