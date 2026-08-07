<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md 8.3 - the closed event taxonomy of the student
 * activity log.
 *
 * Closed on purpose: adding a value requires a migration, because the column
 * is an ENUM. An open string column would accumulate near-duplicate spellings
 * from four different modules and the Activity Log tab would stop being
 * filterable within a term.
 *
 * The cases here MUST stay in step with the ENUM in
 * 2026_08_07_210004_create_student_activity_logs_table.php.
 */
enum StudentActivityEvent: string
{
    case Admitted = 'admitted';
    case Enrolled = 'enrolled';
    case ClassTransferred = 'class_transferred';
    case StreamChanged = 'stream_changed';
    case Suspended = 'suspended';
    case Reinstated = 'reinstated';
    case Withdrawn = 'withdrawn';
    case TransferredOut = 'transferred_out';
    case Graduated = 'graduated';
    case Promoted = 'promoted';
    case Repeated = 'repeated';
    case MarksPublished = 'marks_published';
    case ReportCardPrinted = 'report_card_printed';
    case InvoiceIssued = 'invoice_issued';
    case PaymentReceived = 'payment_received';
    case DisciplineCaseOpened = 'discipline_case_opened';
    case SanctionApplied = 'sanction_applied';
    case DocumentUploaded = 'document_uploaded';
    case DocumentVerified = 'document_verified';
    case GuardianLinked = 'guardian_linked';
    case GuardianUnlinked = 'guardian_unlinked';
    case MedicalRecordAdded = 'medical_record_added';
    case AttendanceFlagged = 'attendance_flagged';
    case MatriculeFinalised = 'matricule_finalised';
}
