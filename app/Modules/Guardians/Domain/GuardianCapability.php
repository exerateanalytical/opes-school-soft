<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * One case per ROW of the docs/specs/07-students.md 7.5 authorization matrix,
 * in the spec's own order, with the row number carried in the case name.
 *
 * The row number is part of the identity on purpose. 7.5's deny-by-default
 * reading rule requires the route-enumeration suite to key every guardian-
 * reachable route to "a 7.5 row number"; a capability that cannot name its row
 * cannot be allow-listed, which is exactly the intended friction when someone
 * adds a route next sprint.
 *
 * Rows 8, 25, 28, 30 and 32 are the "nobody" rows. They exist as cases rather
 * than as omissions so that the truth table can assert, positively, that they
 * are denied under EVERY flag combination. An omitted capability would also be
 * denied - by the default - but nothing would fail if someone later added it
 * with a permissive rule.
 */
enum GuardianCapability: string
{
    case R01ViewChildIdentity = 'child.identity.view';
    case R02ViewChildProfileDetail = 'child.profile_detail.view';
    case R03ViewChildEmergencyMedical = 'child.medical_emergency.view';
    case R04ViewChildFullMedical = 'child.medical_full.view';
    case R05ViewReportCard = 'results.report_card.view';
    case R06DownloadReportCard = 'results.report_card.download';
    case R07ViewPublishedMarks = 'results.marks_published.view';
    case R08ViewUnpublishedMarks = 'results.marks_unpublished.view';
    case R09ViewRankAndClassMean = 'results.rank.view';
    case R10ViewAnnualAverageAndPromotion = 'results.promotion.view';
    case R11ViewAttendanceSummary = 'results.attendance_summary.view';
    case R12ViewAttendanceDetail = 'results.attendance_detail.view';
    case R13ViewInvoices = 'fees.invoices.view';
    case R14ViewFeeStatement = 'fees.statement.view';
    case R15ViewReceipts = 'fees.receipts.view';
    case R16ViewOwnPayments = 'fees.payments_own.view';
    case R17ViewOtherGuardianPayments = 'fees.payments_other.view';
    case R18InitiatePayment = 'fees.payment.initiate';
    case R19ViewDisciplineList = 'discipline.list.view';
    case R20ViewDisciplineNarrative = 'discipline.narrative.view';
    case R21AcknowledgeSanction = 'discipline.sanction.acknowledge';
    case R22ViewSchoolIssuedDocuments = 'documents.school_issued.view';
    case R23ViewGuardianSuppliedDocuments = 'documents.guardian_supplied.view';
    case R24UploadDocument = 'documents.upload';
    case R25DeleteDocument = 'documents.delete';
    case R26ViewTimetableAndAnnouncements = 'school.timetable.view';
    case R27RequestGuardianMeeting = 'meeting.request';
    case R28EditChildRecord = 'child.record.edit';
    case R29EditOwnContactDetails = 'guardian.own_contact.edit';
    case R30EditAuthorizationFlag = 'guardian.authorization.edit';
    case R31ViewOtherGuardiansOfChild = 'child.guardians.list';
    case R32AnythingForAnUnlinkedChild = 'child.unlinked.any';

    /** The 7.5 row number this capability transcribes. */
    public function matrixRow(): int
    {
        return (int) substr($this->name, 1, 2);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
