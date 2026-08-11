<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Granular permissions, named module.action.
 *
 * An enum rather than free strings so a typo fails at analysis time instead of
 * silently matching nothing and denying access for reasons nobody can find.
 * 00-core 9.1: every permission is individually grantable on top of the role
 * baseline.
 *
 * This is the Phase 0B set. Later phases ADD cases as their modules land; they
 * must not rename existing ones, because role seeds and granted permissions
 * reference the values.
 */
enum Permission: string
{
    case UserView = 'user.view';
    case UserManage = 'user.manage';
    case UserSetPassword = 'user.set_password';
    case RoleAssign = 'role.assign';
    case PermissionGrant = 'permission.grant';

    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';

    case SettingView = 'setting.view';
    case SettingEdit = 'setting.edit';
    case SettingEditEngine = 'setting.edit_engine';

    case AcademicsView = 'academics.view';
    case AcademicsManage = 'academics.manage';

    case StudentsView = 'students.view';
    case StudentsManage = 'students.manage';

    // Deliberately separate from students.manage (07-students 6.4): finalising
    // a matricule is irreversible, so the right to do it is granted on its own.
    //
    // 07-students spells this `students.matricule.finalise`. The value here is
    // two-segment because every permission in this enum is `module.action` and
    // a test enforces it - not house style for its own sake: these values are
    // also translation keys, and Laravel reads a dot as a nested-array step,
    // which already broke label() once. The right the spec describes is
    // unchanged; only the spelling is.
    case StudentsMatriculeFinalise = 'students.finalise_matricule';

    case GuardiansManage = 'guardians.manage';
    case AdmissionsManage = 'admissions.manage';

    // Assessment, 00-core 9.1 / 01-assessment 7.4. Entry and validation are
    // separate rights because the MINESEC flow is two-person by design: the
    // teacher enters, someone else validates. `reports.publish` is separate
    // again - publication is the irreversible step that puts a bulletin in a
    // guardian's hands (01-assessment 13.2).
    //
    // Two segments only, like every case above: these values double as
    // translation keys and Laravel reads a dot as a nested-array step.
    case MarksEnter = 'marks.enter';
    case MarksValidate = 'marks.validate';
    case AssessmentConfigure = 'assessment.configure';
    case ReportsPublish = 'reports.publish';
    case ReportsView = 'reports.view';

    case FeeView = 'fee.view';
    case FeeCollect = 'fee.collect';
    case FeeVoid = 'fee.void';

    // 04-fees §2: shaping the fee catalogue (categories, items, structures,
    // instalment plans) is a different right from collecting against it. The
    // Bursar collects; the Accountant and Administrator configure - pricing
    // policy stays out of the hands that touch the cash.
    case FeeConfigure = 'fee.configure';

    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';

    // 02-accounting §3/§5/§6: configuring journals, opening a fiscal year and
    // its accounting periods, and closing/unlocking a period are distinct
    // from posting an entry (ledger.post) - they shape what the ledger IS,
    // not what gets recorded in it. Kept separate so a bursar-adjacent role
    // could in principle post without being able to lock a period out from
    // under everyone else.
    case LedgerConfigure = 'ledger.configure';

    case BackupRun = 'backup.run';
    case BackupRestore = 'backup.restore';
    case LicenceManage = 'licence.manage';

    // Phase 7 (docs/plans/phase-07.md §3): the year-rollover wizard. One
    // permission gates the whole run - starting it, every step's Apply, and
    // the undo - because the wizard is a single consequential annual
    // operation, not a bundle of separable rights (08-operations §6.1). The
    // step Actions gate on this VALUE by string constant; this case is their
    // compile-time face.
    case RolloverRun = 'rollover.run';

    // Phase 8 (docs/plans/phase-08.md §1): timetable, attendance, discipline,
    // promotion, school calendar. Two segments only, like every case above -
    // these values double as translation keys and Laravel reads a dot as a
    // nested-array step.
    //
    // attendance.take is the outer gate only: the Action further scopes a
    // Teacher to the class groups they are actually allocated to, same
    // pattern as marks.enter (01-assessment 7.5).
    case TimetableView = 'timetable.view';
    case TimetableManage = 'timetable.manage';

    case AttendanceView = 'attendance.view';
    case AttendanceTake = 'attendance.take';
    // Amending re-opens a submitted register - a heavier right than taking
    // it, so it stays with school leadership rather than the class teacher.
    case AttendanceAmend = 'attendance.amend';
    case AttendanceJustify = 'attendance.justify';

    // Also gates the student-profile Discipline tab (07-students
    // `students.discipline.view` semantics; spelled two-segment here for the
    // same translation-key reason as StudentsMatriculeFinalise above).
    case DisciplineView = 'discipline.view';
    case DisciplineManage = 'discipline.manage';

    // Evaluate and apply are separate rights because apply is the
    // irreversible step (07-students §10.6): it closes segments and creates
    // next-year enrolments, so it is deliberately narrower than evaluate.
    case PromotionEvaluate = 'promotion.evaluate';
    case PromotionApply = 'promotion.apply';

    case CalendarManage = 'calendar.manage';

    // Phase 5 (docs/plans/phase-05.md §5): Procurement & Tax. The values
    // mirror the string constants the F1-F4 packages gate on
    // (Procurement\Domain\{Procurement,SupplierInvoice,SupplierPayment}
    // Permission) - this enum is their compile-time face, added by the F5
    // wiring pass. Two segments only, like every case above.
    //
    // The SoD pairs are deliberate: creating an invoice, approving it,
    // recording a payment, approving it, and voiding it are five distinct
    // rights, split across roles in Role::defaultPermissions() so no single
    // baseline both authors and approves the same money movement.
    case ProcurementView = 'procurement.view';
    case ProcurementSupplierManage = 'procurement.supplier_manage';
    case ProcurementSupplierOverrideDuplicate = 'procurement.supplier_override_duplicate';
    case ProcurementRequisitionApprove = 'procurement.requisition_approve';
    case ProcurementOrderManage = 'procurement.order_manage';
    case ProcurementOrderApprove = 'procurement.order_approve';
    case ProcurementInvoiceView = 'procurement.invoice_view';
    case ProcurementInvoiceCreate = 'procurement.invoice_create';
    case ProcurementInvoiceApprove = 'procurement.invoice_approve';
    case ProcurementInvoiceApproveUnmatched = 'procurement.invoice_approve_unmatched';
    case ProcurementInvoiceOverrideMatch = 'procurement.invoice_override_match';
    case ProcurementInvoiceWaiveWithholding = 'procurement.invoice_waive_withholding';
    case ProcurementPaymentRecord = 'procurement.payment_record';
    case ProcurementPaymentApprove = 'procurement.payment_approve';
    case ProcurementPaymentVoid = 'procurement.payment_void';

    // Tax (03-tax-procurement §7): reading the dashboard, generating
    // declarations and recording their filing are three rights - filing is
    // the one that stamps the DGI acknowledgement and arms the DSF reopen
    // block, so it is granted narrower than generation.
    case TaxView = 'tax.view';
    case TaxDeclare = 'tax.declare';
    case TaxFile = 'tax.file';

    // 03-tax-procurement §2.2 invariant 1: correcting a CONFIRMED
    // NIU/identity is its own permission-gated act (reason + document),
    // granted to almost nobody - a NIU typo silently propagates onto every
    // printed invoice and every filed declaration.
    case FiscalIdentityCorrect = 'fiscal_identity.correct';

    // Phase 12 (docs/plans/phase-12-13.md §12.5): portals, API tokens,
    // webhooks and the communication outbox. portal.access is the outer gate
    // only - what a guardian may actually see per child is decided by
    // GuardianScopeMatrix (07-students §7.5), never by this permission alone.
    // Two segments only, like every case above: these values double as
    // translation keys and Laravel reads a dot as a nested-array step.
    case PortalAccess = 'portal.access';
    case PortalManage = 'portal.manage';

    // docs/specs/2026-08-11-guardian-mobile-api-v1.md §2.3: the mobile app's
    // two token abilities. IssueApiToken validates abilities against THIS
    // enum, so a device token cannot be minted carrying a scope that has no
    // case here - which is exactly why they are cases and not free strings.
    //
    // They gate PRESENCE on the /v1/me surface; portal.access remains the
    // outer door and GuardianScopeMatrix (07-students §7.5) remains the only
    // decision about what a guardian may see per child. A token holding
    // portal.read still sees nothing its links do not grant.
    case PortalRead = 'portal.read';
    case PortalWrite = 'portal.write';
    case ApiTokenManage = 'api.manage_tokens';
    case WebhookManage = 'webhook.manage';
    case CommunicationSend = 'communication.send';
    case CommunicationView = 'communication.view';

    // Phase 13 (10-documents §19): the document platform. Reprinting a money
    // document (receipt/invoice/credit note/refund/payslip) is a separate,
    // narrower right than reprinting in general, mirroring the payment-void
    // segregation in 04-fees. override_gate lets the Principal issue a
    // Transfer/Leaving/Character certificate despite a clearance or
    // discipline block, always with a recorded reason.
    //
    // 10-documents spells the last four `documents.reprint.financial`,
    // `documents.template.manage` etc.; spelled two-segment here for the same
    // translation-key reason as StudentsMatriculeFinalise above.
    case DocumentsPrint = 'documents.print';
    case DocumentsReprint = 'documents.reprint';
    case DocumentsReprintFinancial = 'documents.reprint_financial';
    case DocumentsBulkPrint = 'documents.bulk_print';
    case DocumentsRevoke = 'documents.revoke';
    case DocumentsTemplateManage = 'documents.template_manage';
    case DocumentsOverrideGate = 'documents.override_gate';

    // Phase 9: assets, inventory/stores, and library. Mirrors the string
    // constants each module's Actions already gate on
    // (Assets\Domain\AssetPermission, Inventory\Domain\InventoryPermission,
    // Library\Domain\LibraryPermission) - this enum is their compile-time
    // face, added by the nav/screen wiring pass. Two segments only.
    case AssetView = 'asset.view';
    case AssetManage = 'asset.manage';
    case AssetDepreciate = 'asset.depreciate';
    case AssetDispose = 'asset.dispose';

    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';
    case InventoryPost = 'inventory.post';

    case LibraryView = 'library.view';
    case LibraryManage = 'library.manage';
    case LibraryCirculate = 'library.circulate';
    case LibraryWaiveFine = 'library.waive_fine';

    // Phase 10 (Welfare): transport, hostel, medical, visitors, insurance.
    // Mirrors Welfare\Domain\{Transport,Hostel,Medical,Visitor,Insurance}
    // Permission string constants, same pattern as Phase 9 above.
    case TransportView = 'transport.view';
    case TransportManage = 'transport.manage';

    case HostelView = 'hostel.view';
    case HostelManage = 'hostel.manage';

    case MedicalView = 'medical.view';
    case MedicalManage = 'medical.manage';

    case VisitorManage = 'visitor.manage';

    case InsuranceView = 'insurance.view';
    case InsuranceManage = 'insurance.manage';

    // Phase 11 (HR/Payroll). Mirrors HR\Domain\HrPermission and
    // Payroll\Domain\PayrollPermission string constants.
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case LeaveApprove = 'leave.approve';
    case TimesheetValidate = 'timesheet.validate';

    case PayrollView = 'payroll.view';
    case PayrollRun = 'payroll.run';
    case PayrollApprove = 'payroll.approve';
    case PayrollReverse = 'payroll.reverse';
    case PayrollConfigure = 'payroll.configure';
    case PayrollPay = 'payroll.pay';
    case PayrollOverrideRiskClass = 'payroll.override_risk_class';
    case PayrollClassifyNonEmployee = 'payroll.classify_non_employee';
    case DeclarationFile = 'declaration.file';

    // 02-accounting §21.3 expense capture. Mirrors
    // Accounting\Domain\ExpensePermission's string constants, same pattern
    // as the Procurement/Welfare blocks above. Only TWO cases: posting an
    // approved expense reuses the existing `ledger.post`, and reading the
    // register reuses `ledger.view` - putting an expense in the ledger is
    // the same act, and the same hands, as posting any other entry.
    //
    // The pair is deliberate and is the maker-checker of §21.3: the clerk
    // who records petty cash holds `expense.record`, the bursar who signs it
    // off holds `expense.approve`, and ApproveExpense refuses submitter ==
    // approver at runtime even for someone holding both.
    case ExpenseRecord = 'expense.record';
    case ExpenseApprove = 'expense.approve';

    public function label(string $locale = 'en'): string
    {
        // Permission values contain a dot ('user.view'), and the translator
        // reads dots as nested-array segments - so 'opes.permissions.user.view'
        // would look for ['permissions']['user']['view'] and never find the
        // flat key that lang/*/opes.php actually declares. Fetch the group and
        // index it directly. Missing keys still return the raw key, which is
        // what LocalisationTest asserts against.
        $labels = trans('opes.permissions', [], $locale);

        if (is_array($labels) && is_string($labels[$this->value] ?? null)) {
            return $labels[$this->value];
        }

        return 'opes.permissions.'.$this->value;
    }
}
