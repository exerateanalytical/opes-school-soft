<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.5 - the guardian authorization matrix, cell by
 * cell. "The following table IS the specification"; this class is its only
 * transcription and nothing else in the codebase may make this decision.
 *
 * Pure: no Laravel, no Eloquent, no clock, no database. It takes the flags of
 * one link and a capability and returns a boolean. Every input that could vary
 * - today's date, the guardian's status, the link's date window - has already
 * been resolved into GuardianAuthorizationFlags by the caller, which is what
 * 7.3 means by "evaluated once at transaction start and passed down".
 *
 * Deny by default: the match is exhaustive over the enum, but the guard clause
 * and the `nobody` rows mean that any future case added without a rule here
 * fails to compile under PHPStan's match exhaustiveness rather than silently
 * granting.
 */
final class GuardianScopeMatrix
{
    public static function allows(GuardianAuthorizationFlags $flags, GuardianCapability $capability): bool
    {
        // 7.5: every grant is conjunctive with a valid link (7.3) and an
        // active guardian. An expired link grants NOTHING - and unlike the
        // v1 behaviour this replaces, that includes the periods during which
        // the link WAS valid.
        //
        // Row 16 is the sole exception the spec names: a guardian's own
        // payment records are financial records of their own transactions and
        // 04-fees never deletes them. That exception is NOT expressed here,
        // because this class only ever sees one link. It is expressed where it
        // belongs - on the payment query, which filters by
        // `payer_guardian_id`, not by link scope. Granting row 16 to an
        // expired link here would leak the CHILD's existence, which is row 1,
        // and row 1 is not exempt.
        if (! $flags->passesConjunctiveGate()) {
            return false;
        }

        return match ($capability) {
            // Row 1 - any valid link. Knowing the child exists is the floor.
            GuardianCapability::R01ViewChildIdentity => true,

            // Row 2 - has_custody.
            GuardianCapability::R02ViewChildProfileDetail => $flags->hasCustody,

            // Row 3 - has_custody OR is_emergency_contact. The emergency
            // contact needs allergies at the moment they are needed; that is
            // the entire point of the flag.
            GuardianCapability::R03ViewChildEmergencyMedical => $flags->hasCustody || $flags->isEmergencyContact,

            // Row 4 - has_custody. Genotype and blood group are NOT emergency
            // triage data in this product's threat model; row 3 is the
            // narrowed view, row 4 is the whole record.
            GuardianCapability::R04ViewChildFullMedical => $flags->hasCustody,

            // Rows 5, 6, 7 - receives_reports.
            GuardianCapability::R05ViewReportCard,
            GuardianCapability::R06DownloadReportCard,
            GuardianCapability::R07ViewPublishedMarks => $flags->receivesReports,

            // Row 8 - NOBODY. Publication state is checked first, always. This
            // returns false unconditionally so that a caller who forgets the
            // publication check still cannot serve an unpublished mark.
            GuardianCapability::R08ViewUnpublishedMarks => false,

            // Row 9 - receives_reports, and (the dagger) only the child's own
            // rank plus the class denominator. The narrowing is a property of
            // the projection the caller builds, not of this boolean; this
            // class answers "may they see A rank", the query answers "whose".
            GuardianCapability::R09ViewRankAndClassMean => $flags->receivesReports,

            // Row 10 - receives_reports, and only once the promotion decision
            // is `applied`. That second conjunct lives in the promotion module
            // (07-students 11) and is ANDed by the caller; it can only narrow.
            GuardianCapability::R10ViewAnnualAverageAndPromotion => $flags->receivesReports,

            // Rows 11, 12 - has_custody OR receives_reports.
            GuardianCapability::R11ViewAttendanceSummary,
            GuardianCapability::R12ViewAttendanceDetail => $flags->hasCustody || $flags->receivesReports,

            // Rows 13, 14, 15, 17 - receives_invoices OR is_fee_payer.
            GuardianCapability::R13ViewInvoices,
            GuardianCapability::R14ViewFeeStatement,
            GuardianCapability::R15ViewReceipts,
            GuardianCapability::R17ViewOtherGuardianPayments => $flags->receivesInvoices || $flags->isFeePayer,

            // Row 16 - any valid link; it is their own transaction.
            GuardianCapability::R16ViewOwnPayments => true,

            // Row 18 - is_fee_payer ALONE. receives_invoices is a read grant;
            // moving money is not implied by being allowed to see the bill.
            GuardianCapability::R18InitiatePayment => $flags->isFeePayer,

            // Rows 19, 21 - has_custody.
            GuardianCapability::R19ViewDisciplineList,
            GuardianCapability::R21AcknowledgeSanction => $flags->hasCustody,

            // Row 20 - has_custody AND case visibility = 'guardian'. Cases
            // naming another minor are `internal` and invisible to every
            // guardian; that conjunct is applied by the discipline query,
            // which owns the visibility column.
            GuardianCapability::R20ViewDisciplineNarrative => $flags->hasCustody,

            // Row 22 - has_custody OR receives_reports (school-issued).
            GuardianCapability::R22ViewSchoolIssuedDocuments => $flags->hasCustody || $flags->receivesReports,

            // Rows 23, 24 - has_custody. An upload lands `unverified`, never
            // auto-verified; that is the uploader's job, not this boolean's.
            GuardianCapability::R23ViewGuardianSuppliedDocuments,
            GuardianCapability::R24UploadDocument => $flags->hasCustody,

            // Row 25 - NOBODY. Staff only.
            GuardianCapability::R25DeleteDocument => false,

            // Row 26 - any valid link.
            GuardianCapability::R26ViewTimetableAndAnnouncements => true,

            // Row 27 - has_custody.
            GuardianCapability::R27RequestGuardianMeeting => $flags->hasCustody,

            // Row 28 - NOBODY edits the child's record from the portal.
            GuardianCapability::R28EditChildRecord => false,

            // Row 29 - any valid link, own row only. "Own row only" is
            // resolved by the caller from portal_user_id -> guardian_id; this
            // class is only ever handed the requester's own link.
            GuardianCapability::R29EditOwnContactDetails => true,

            // Row 30 - NOBODY. Registrar only, via SetGuardianAuthorization
            // (7.6). A guardian editing their own authorization flags is the
            // single worst failure this matrix exists to prevent.
            GuardianCapability::R30EditAuthorizationFlag => false,

            // Row 31 - has_custody; names and relationship only, never the
            // other guardian's ID number or address. Again a projection
            // narrowing, enforced by the query that builds the list.
            GuardianCapability::R31ViewOtherGuardiansOfChild => $flags->hasCustody,

            // Row 32 - NOBODY. Reaching this case at all means the caller
            // resolved a link for a child the guardian is not linked to, which
            // is a bug; false is the right answer either way.
            GuardianCapability::R32AnythingForAnUnlinkedChild => false,
        };
    }
}
