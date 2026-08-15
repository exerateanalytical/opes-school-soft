<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\Permission;

/**
 * The sidebar, from docs/specs/09-ui.md section 2.
 *
 * Every item is a real link. Modules that are not built yet carry
 * `built => false`: their link leads to an in-shell placeholder page at the
 * SAME URL the real module will later occupy (so bookmarks survive the
 * module landing), and the nav renders a small "soon" chip beside them.
 * Hiding future modules would misrepresent the product; a dead grey label
 * reads as a defect to an operator, which is exactly how it was reported.
 *
 * Each item also carries the permission that gates it, so the nav and the
 * route agree by construction. Placeholder pages contain no data, so
 * `permission => null` (auth only) is correct for them.
 *
 * @phpstan-type NavItem array{key: string, route: string|null, permission: Permission|null, enabled: bool, built: bool}
 */
final class Navigation
{
    /**
     * @return list<NavItem>
     */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'route' => '/dashboard', 'permission' => null, 'enabled' => true, 'built' => true],
            // The hold/resume worklist behind the popup-form system:
            // POS-style hold-order for any long form.
            ['key' => 'unfinished_work', 'route' => '/unfinished-work', 'permission' => null, 'enabled' => true, 'built' => true],
            // In-platform messaging: open to any authenticated user,
            // membership-gated rather than RBAC-gated (00-core §6.2).
            ['key' => 'messages', 'route' => '/messages', 'permission' => null, 'enabled' => true, 'built' => true],
            // The route's own comment calls /marks "the single highest-
            // traffic academic screen", and a 244-page crawl found zero links
            // to it anywhere in the product - a teacher could reach their
            // primary daily task only by typing the URL. Gated on
            // marks.enter, matching the route, per this file's nav-and-route-
            // agree-by-construction contract.
            ['key' => 'marks', 'route' => '/marks', 'permission' => Permission::MarksEnter, 'enabled' => true, 'built' => true],
            // Homework/assignments - not in the spec set, which is
            // compliance-first; gated on the same marks.enter a teacher
            // already holds.
            ['key' => 'homework', 'route' => '/homework', 'permission' => Permission::MarksEnter, 'enabled' => true, 'built' => true],
            ['key' => 'admissions', 'route' => '/admissions', 'permission' => Permission::AdmissionsManage, 'enabled' => true, 'built' => true],
            ['key' => 'students', 'route' => '/students', 'permission' => Permission::StudentsView, 'enabled' => true, 'built' => true],
            // Wiring pass: the guardian directory/list screen now lives at
            // this URL (guardian RECORDS have existed since Phase 2, reached
            // through a student's profile; what was missing was this list).
            ['key' => 'guardians', 'route' => '/guardians', 'permission' => Permission::GuardiansManage, 'enabled' => true, 'built' => true],
            // 07-students §7.8: individual guardian meetings - schema
            // shipped Phase 2 with no UI.
            ['key' => 'guardian_meetings', 'route' => '/guardians/meetings', 'permission' => Permission::GuardiansManage, 'enabled' => true, 'built' => true],
            // The Parent-Teacher Association - officers and general
            // meetings, not in docs/specs.
            ['key' => 'pta', 'route' => '/guardians/pta', 'permission' => Permission::GuardiansManage, 'enabled' => true, 'built' => true],
            // 00-core §15 Phase 2: bulk onboarding from a CSV.
            ['key' => 'import', 'route' => '/students/import', 'permission' => Permission::StudentsManage, 'enabled' => true, 'built' => true],
            // The end-of-year promotion wizard. It was routed, registered and
            // tested but never given a sidebar entry, so the only way to reach
            // it was to type the URL. Gated on promotion.evaluate to match its
            // route's outer gate (the apply step is gated harder inside the
            // component), per this file's nav-and-route-agree contract.
            ['key' => 'promotion', 'route' => '/students/promotion', 'permission' => Permission::PromotionEvaluate, 'enabled' => true, 'built' => true],
            // Alumni (gap #3, 2026-08-12 gap analysis): the register of
            // graduates the school stays in touch with. Sits directly after
            // promotion because conversion is the step after graduating.
            // Gated on alumni.view to match its route, per this file's
            // nav-and-route-agree-by-construction contract.
            ['key' => 'alumni', 'route' => '/alumni', 'permission' => Permission::AlumniView, 'enabled' => true, 'built' => true],
            // Phase 11 F1/wiring pass: staff directory.
            ['key' => 'staff', 'route' => '/staff', 'permission' => Permission::StaffView, 'enabled' => true, 'built' => true],
            // Phase 11 F2/wiring pass: payroll runs.
            ['key' => 'payroll', 'route' => '/payroll', 'permission' => Permission::PayrollView, 'enabled' => true, 'built' => true],
            // Gated on manage, not view: the route behind it is
            // `can:academics.manage`, and this file's contract is that the nav
            // and the route agree by construction. A Teacher with only
            // `academics.view` must not be shown a link that answers 403.
            ['key' => 'academics', 'route' => '/academics/settings', 'permission' => Permission::AcademicsManage, 'enabled' => true, 'built' => true],
            ['key' => 'classes', 'route' => '/classes', 'permission' => Permission::AcademicsView, 'enabled' => true, 'built' => true],
            ['key' => 'subjects', 'route' => '/subjects', 'permission' => Permission::AcademicsView, 'enabled' => true, 'built' => true],
            // Curriculum framework (gap #2 of the 2026-08-12 module gap
            // analysis): the versioned programme of study behind each
            // subject. Gated on curriculum.view, matching its route, per
            // this file's nav-and-route-agree-by-construction contract.
            ['key' => 'curriculum', 'route' => '/curriculum', 'permission' => Permission::CurriculumView, 'enabled' => true, 'built' => true],
            // Phase 8 F1/F5: real screens now live behind these URLs, so the
            // nav flips to built => true with the permission that gates the
            // route below - nav and route agree by construction, per this
            // file's documented contract.
            ['key' => 'timetable', 'route' => '/timetable', 'permission' => Permission::TimetableView, 'enabled' => true, 'built' => true],
            // Phase 8 F2/F5: same flip - the Attendance Management screen at
            // /attendance is the sidebar's target, matching its route's
            // `can:attendance.view` gate.
            ['key' => 'attendance', 'route' => '/attendance', 'permission' => Permission::AttendanceView, 'enabled' => true, 'built' => true],
            // Wiring pass: exam SCHEDULING (sittings, invigilators, seating)
            // shipped with Phase 3's Actions; marks entry lives separately at
            // /marks. This screen now exists at /examinations.
            ['key' => 'examinations', 'route' => '/examinations', 'permission' => Permission::AssessmentConfigure, 'enabled' => true, 'built' => true],
            // Wiring pass: computed period results/class statistics browse
            // screen now lives at /results.
            ['key' => 'results', 'route' => '/results', 'permission' => Permission::AcademicsView, 'enabled' => true, 'built' => true],
            // Fees (Phase 6): the finance item lands on the invoices list;
            // the cashier and per-student statements hang off it. Gated on
            // fee.view, matching its route, per this file's nav-and-route-
            // agree-by-construction contract; the ACT of collecting is gated
            // harder (fee.collect) inside the cashier screen, the same
            // screen-vs-write split the ledger item uses.
            ['key' => 'finance', 'route' => '/finance/invoices', 'permission' => Permission::FeeView, 'enabled' => true, 'built' => true],
            // The general ledger (09-ui.md's Finance section covers fees and
            // accounting together at `/finance`, but that dashboard route does
            // not exist yet). This item is scoped to the ledger screens Phase 4
            // ships: chart of accounts, journal entries, trial balance. Gated
            // on ledger.view so the sidebar and the routes below agree by
            // construction, per this file's documented contract.
            // The accountant's overview, deliberately ABOVE the ledger detail:
            // 02-accounting §21.3's dashboard is where a bursar starts the
            // day, and the treasury panel there is the one place cash, bank,
            // MTN and Orange are shown as four separate balances.
            ['key' => 'finance_dashboard', 'route' => '/finance/dashboard', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            ['key' => 'ledger', 'route' => '/ledger/chart-of-accounts', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // Expense capture (02-accounting §21.3): the petty cash-and-receipt
            // spend that never becomes a supplier invoice.
            ['key' => 'expenses', 'route' => '/accounting/expenses', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // Bilan / Compte de resultat / Flux, with comparatives.
            ['key' => 'statements', 'route' => '/reports/statements', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // 02-accounting §14: the four AUDCIF Art. 19 statutory books.
            ['key' => 'books', 'route' => '/accounting/books', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // AUDCIF §14.4: the generated system documentation.
            ['key' => 'system_documentation', 'route' => '/accounting/system-documentation', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // Outbound webhook endpoints - Phase 12, shipped schema-only
            // until now.
            ['key' => 'webhooks', 'route' => '/webhooks', 'permission' => Permission::ApiTokenManage, 'enabled' => true, 'built' => true],
            // 02-accounting §13: each treasury float against its own
            // operator statement (MTN and Orange reconcile separately).
            ['key' => 'reconciliation', 'route' => '/accounting/reconciliation', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // 02-accounting §16: budget and budget-vs-actual.
            ['key' => 'budgets', 'route' => '/accounting/budgets', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // 2026-08-12-accounting-finance-architecture §4: the integrity
            // surface - control accounts, and which statutory gates are still
            // open. Read-only; it reports and never posts.
            ['key' => 'accounting_review', 'route' => '/accounting/review', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // The journal worklist sits beside it: every category is legal,
            // they are simply the entries an auditor asks about first.
            ['key' => 'accounting_review_journals', 'route' => '/accounting/review/journals', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // Procurement (Phase 5): lands on the supplier register; the
            // rest of the P2P chain (requisitions, orders, receipts,
            // invoices, payments) hangs off it. Gated on procurement.view,
            // matching its route, per this file's nav-and-route-agree-by-
            // construction contract; the ACTS (approve, pay, void) are
            // gated harder inside the screens and Actions.
            ['key' => 'procurement', 'route' => '/procurement/suppliers', 'permission' => Permission::ProcurementView, 'enabled' => true, 'built' => true],
            // Tax & declarations (Phase 5): the compliance dashboard.
            // Gated on tax.view to match its route; generating and filing
            // are gated harder (tax.declare / tax.file) on their screens
            // and Actions. The tax CONFIGURATION cockpit lives under
            // /settings/tax behind ledger.configure.
            ['key' => 'tax', 'route' => '/tax', 'permission' => Permission::TaxView, 'enabled' => true, 'built' => true],
            // Phase 9 F4/wiring pass: the library catalogue/circulation
            // screen now lives at this URL.
            ['key' => 'library', 'route' => '/library', 'permission' => Permission::LibraryView, 'enabled' => true, 'built' => true],
            // Phase 9 F1/wiring pass: stores/stock screen.
            ['key' => 'inventory', 'route' => '/inventory', 'permission' => Permission::InventoryView, 'enabled' => true, 'built' => true],
            // Phase 9 F1-F2/wiring pass: the asset register + depreciation
            // screen.
            ['key' => 'assets', 'route' => '/assets', 'permission' => Permission::AssetView, 'enabled' => true, 'built' => true],
            // Activities (gap-analysis #1): clubs, sports teams, events and
            // excursions at /activities. Gated on activity.view, matching
            // its route, per this file's nav-and-route-agree-by-construction
            // contract; creating/enrolling/consent are gated harder
            // (activity.manage) inside the screens and Actions.
            ['key' => 'activities', 'route' => '/activities', 'permission' => Permission::ActivityView, 'enabled' => true, 'built' => true],
            // Phase 10 W1/W5: the Transport Management screen now lives at
            // this URL, so the nav flips to built => true with the
            // permission that gates the route above - nav and route agree by
            // construction, per this file's documented contract.
            ['key' => 'transport', 'route' => '/transport', 'permission' => Permission::TransportView, 'enabled' => true, 'built' => true],
            // Phase 10 W2/W5: same flip - Hostel Management.
            ['key' => 'hostel', 'route' => '/hostel', 'permission' => Permission::HostelView, 'enabled' => true, 'built' => true],
            // Phase 10 W3/W5: Medical (consultations + referrals).
            ['key' => 'medical', 'route' => '/medical', 'permission' => Permission::MedicalView, 'enabled' => true, 'built' => true],
            // Phase 10 W4/W5: the visitor gate register.
            ['key' => 'visitors', 'route' => '/visitors', 'permission' => Permission::VisitorManage, 'enabled' => true, 'built' => true],
            // Phase 10 W5/W5: insurance (policies, enrolment, claims).
            ['key' => 'insurance', 'route' => '/insurance', 'permission' => Permission::InsuranceView, 'enabled' => true, 'built' => true],
            // The route comment says discipline is "reached from within (the
            // student profile's Discipline tab and the Welfare area)" - but
            // that tab is disabled and there is no Welfare area, so the
            // screen had zero inbound links. Gated on discipline.view,
            // matching the route.
            ['key' => 'discipline', 'route' => '/welfare/discipline', 'permission' => Permission::DisciplineView, 'enabled' => true, 'built' => true],
            // The front-desk certificate check (10-documents §17.2) had no
            // entry point anywhere. Auth-only, exactly like its route: the
            // page holds no student data by construction.
            ['key' => 'documents_verify', 'route' => '/documents/verify', 'permission' => null, 'enabled' => true, 'built' => true],
            // Reports hub (2026-08 build): a categorised catalogue of every
            // report screen (financial, academic, HR, procurement, welfare,
            // assets, library, tax, students) with Excel/PDF/print export.
            ['key' => 'reports', 'route' => '/reports', 'permission' => Permission::ReportsView, 'enabled' => true, 'built' => true],
            // Operations (Phase 7): the year-rollover wizard. The mockups'
            // sidebars carry operations entries (Backup & Restore, Database
            // Maintenance in flow wizards.png 10/12), and 08-operations §6.1
            // calls the rollover the most consequential annual operation -
            // it gets a first-class item rather than hiding behind Settings.
            // Gated on rollover.run, matching its route, per this file's
            // nav-and-route-agree-by-construction contract. The licence
            // panel lives at /settings/licence (licence.manage) and is
            // reached from Settings, not the sidebar.
            ['key' => 'operations', 'route' => '/operations/rollover', 'permission' => Permission::RolloverRun, 'enabled' => true, 'built' => true],
            ['key' => 'users', 'route' => '/users', 'permission' => Permission::UserView, 'enabled' => true, 'built' => true],
            // 09-ui §8.11: the hash-chained audit trail, now viewable and
            // verifiable from the UI rather than only from code.
            ['key' => 'audit_log', 'route' => '/audit-log', 'permission' => Permission::AuditView, 'enabled' => true, 'built' => true],
            // 08-operations §3.8: backups were CLI-only until now. Restore
            // stays behind backup.restore, which even Administrator lacks.
            ['key' => 'backups', 'route' => '/operations/backups', 'permission' => Permission::BackupRun, 'enabled' => true, 'built' => true],
            // 10-documents §18: batch document runs.
            ['key' => 'bulk_prints', 'route' => '/documents/bulk-prints', 'permission' => Permission::DocumentsBulkPrint, 'enabled' => true, 'built' => true],
            // 08-operations §11.1: the queued outbox and its templates. The
            // SMS gateway itself is still deferred; this is the queue.
            ['key' => 'outbox', 'route' => '/communication/outbox', 'permission' => Permission::CommunicationView, 'enabled' => true, 'built' => true],
            ['key' => 'message_templates', 'route' => '/communication/templates', 'permission' => Permission::CommunicationView, 'enabled' => true, 'built' => true],
            // Wiring pass: a read-only browser for SchoolProfile's generic
            // engine-configuration key/value store now lives at /settings.
            // /settings/tax, /settings/fiscal-identity and /settings/licence
            // remain separate screens for their own modules, unaffected.
            // 00-core §16: which blocking gates are still open, and who
            // has to answer each one.
            ['key' => 'setup', 'route' => '/setup', 'permission' => Permission::SettingView, 'enabled' => true, 'built' => true],
            ['key' => 'settings', 'route' => '/settings', 'permission' => Permission::SettingView, 'enabled' => true, 'built' => true],
            // The school's brand-colour picker. Gated on setting.edit rather
            // than .view, matching its route: unlike the settings BROWSER
            // above, this screen has nothing useful to show read-only.
            ['key' => 'branding', 'route' => '/settings/branding', 'permission' => Permission::SettingEdit, 'enabled' => true, 'built' => true],
        ];
    }

    /**
     * The nav keys whose module is not built yet - each serves the shared
     * placeholder page at its own future URL. routes/web.php iterates this so
     * a key added here gets its route by construction, and the
     * PlaceholderRoutesTest walks it so none can silently 404.
     *
     * @return list<string>
     */
    public static function placeholderKeys(): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['key'],
            array_filter(
                self::items(),
                static fn (array $item): bool => ! $item['built'] && is_string($item['route']),
            ),
        ));
    }

    /**
     * @return array<string, string> nav key => path, for placeholder items
     */
    public static function placeholderRoutes(): array
    {
        $routes = [];

        foreach (self::items() as $item) {
            if (! $item['built'] && is_string($item['route'])) {
                $routes[$item['key']] = $item['route'];
            }
        }

        return $routes;
    }
}
