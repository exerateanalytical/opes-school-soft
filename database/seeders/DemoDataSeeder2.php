<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assessment\Actions\CreateFramework;
use App\Modules\Assessment\Actions\DefineComponents;
use App\Modules\Assessment\Actions\OpenAssessmentPeriod;
use App\Modules\Assessment\Actions\SaveMarkBatch;
use App\Modules\Assessment\Actions\ScheduleExam;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Assets\Actions\CommissionAsset;
use App\Modules\Assets\Actions\CreateAssetCategory;
use App\Modules\Assets\Actions\RegisterAsset;
use App\Modules\Assets\Actions\RunDepreciation;
use App\Modules\Guardians\Actions\ActivatePortalAccount;
use App\Modules\Guardians\Actions\IssuePortalInvitation;
use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\HR\Actions\ApproveLeave;
use App\Modules\HR\Actions\OpenStaffContract;
use App\Modules\HR\Actions\RequestLeave;
use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\WorkingTime;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\StartStockTake;
use App\Modules\Library\Actions\AddBookCopies;
use App\Modules\Library\Actions\EnrollLibraryMember;
use App\Modules\Library\Actions\IssueBook;
use App\Modules\Library\Actions\LevyFine;
use App\Modules\Library\Actions\RegisterBook;
use App\Modules\Library\Actions\ReturnBook;
use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Actions\ConfigureEmployerProfile;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Actions\SaveGoodsReceipt;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Actions\SendPurchaseOrder;
use App\Modules\Tax\Actions\ConfigureTaxCode;
use App\Modules\Tax\Actions\ConfigureWithholdingRule;
use App\Modules\Welfare\Actions\AllocateBed;
use App\Modules\Welfare\Actions\AllocateTransport;
use App\Modules\Welfare\Actions\CheckInVisitor;
use App\Modules\Welfare\Actions\CreateRoute;
use App\Modules\Welfare\Actions\EnrollStudentsInPolicy;
use App\Modules\Welfare\Actions\OpenDisciplineCase;
use App\Modules\Welfare\Actions\RecordClaim;
use App\Modules\Welfare\Actions\RecordConsultation;
use App\Modules\Welfare\Actions\ResolveDisciplineCase;
use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Actions\SaveHostel;
use App\Modules\Welfare\Actions\SavePolicy;
use App\Modules\Welfare\Actions\SaveRoom;
use App\Modules\Welfare\Actions\SaveVehicle;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use App\Modules\Welfare\Domain\TransportDirection;
use App\Modules\Welfare\Domain\VisitorHostType;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extends DemoDataSeeder with demo data for every module built after the
 * original seeder: Welfare, Assets, Inventory, Library, Payroll, HR,
 * Procurement, Tax, Assessment, extra Identity users and Guardian portal
 * accounts.
 *
 * Reuses the academic year, fiscal year, students, guardians, staff and
 * enrollments DemoDataSeeder already created - looked up by code/matricule,
 * never recreated. Idempotent the same way: firstOrCreate / where-exists
 * guards per row, no single wrapping transaction, so a partial re-run
 * converges rather than duplicating.
 *
 * Each top-level section is wrapped in try/catch and reports failures via
 * $this->command instead of aborting the whole run - later sections do not
 * depend on an earlier one having fully succeeded.
 */
final class DemoDataSeeder2 extends Seeder
{
    private int $academicYearId;

    private int $fiscalYearId;

    private int $schoolSectionId;

    /** @var list<int> staff_members.id */
    private array $staffIds;

    /** @var list<int> enrollments.id for this academic year */
    private array $enrollmentIds;

    public function run(): void
    {
        $admin = $this->demoAdmin();
        Auth::login($admin);

        $this->academicYearId = (int) DB::table('academic_years')->where('code', '2026-2027')->value('id');
        $this->fiscalYearId = (int) DB::table('fiscal_years')->where('code', '2026')->value('id');
        $this->schoolSectionId = (int) DB::table('school_sections')->value('id');

        $this->staffIds = DB::table('staff_members')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->enrollmentIds = DB::table('enrollments')
            ->where('academic_year_id', $this->academicYearId)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->section('Welfare', fn () => $this->seedWelfare());
        $this->section('Assets', fn () => $this->seedAssets());
        $this->section('Inventory', fn () => $this->seedInventory());
        $this->section('Library', fn () => $this->seedLibrary());
        $this->section('HR contracts & leave', fn () => $this->seedHr());
        $this->section('Payroll', fn () => $this->seedPayroll());
        $this->section('Procurement', fn () => $this->seedProcurement());
        $this->section('Tax', fn () => $this->seedTax());
        $this->section('Assessment', fn () => $this->seedAssessment());
        $this->section('Identity users', fn () => $this->seedUsers());
        $this->section('Guardian portal', fn () => $this->seedGuardianPortal());
        $this->section('Staff portal', fn () => $this->seedStaffPortal());

        $this->command?->info('DemoDataSeeder2 complete.');
    }

    private function section(string $name, callable $fn): void
    {
        try {
            // Restore the admin actor at the top of every section: a prior
            // section may have switched to a different actor (e.g. payroll
            // segregation of duties) and thrown before switching back.
            Auth::login($this->demoAdmin());
            $fn();
            $this->command?->info("[{$name}] done.");
        } catch (Throwable $e) {
            $this->command?->warn("[{$name}] FAILED: ".$e->getMessage());
        }
    }

    private function actor(): Actor
    {
        return Auth::user()->toAuditActor();
    }

    private function demoAdmin(): User
    {
        (new RolePermissionSeeder())->run();

        $user = User::query()->firstOrCreate(
            ['email' => 'demo.admin@opeschool.test'],
            ['name' => 'Demo Admin', 'password' => Str::random(32)],
        );

        if (! $user->hasRole(Role::SuperAdmin->value)) {
            $user->assignRole(Role::SuperAdmin->value);
        }

        return $user->fresh() ?? $user;
    }

    private function accountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }

    // ── Welfare ────────────────────────────────────────────────────────

    private function seedWelfare(): void
    {
        $actor = $this->actor();

        // Transport: 2 routes with stops, 2 vehicles, allocations.
        $routeIds = [];

        foreach ([
            ['code' => 'RT-A', 'name' => 'Route A - Town Centre', 'stops' => ['Central Market', 'City Hall', 'Roundabout']],
            ['code' => 'RT-B', 'name' => 'Route B - Bonaberi', 'stops' => ['Bonaberi Bridge', 'Ndogbong', 'PK14']],
        ] as $r) {
            $existing = DB::table('transport_routes')->where('code', $r['code'])->value('id');

            if (is_numeric($existing)) {
                $routeIds[] = (int) $existing;

                continue;
            }

            $route = app(CreateRoute::class)->handle(null, [
                'code' => $r['code'],
                'name' => $r['name'],
                'is_active' => true,
                'stops' => array_map(fn ($n) => ['name' => $n], $r['stops']),
            ], $actor);

            $routeIds[] = (int) $route->id;
        }

        foreach (['CE-101-BC', 'CE-202-BC'] as $reg) {
            if (DB::table('vehicles')->where('registration_no', $reg)->doesntExist()) {
                app(SaveVehicle::class)->handle(null, [
                    'registration_no' => $reg,
                    'capacity' => 30,
                    'status' => 'operational',
                ], $actor);
            }
        }

        $stopIds = DB::table('transport_stops')->whereIn('route_id', $routeIds)->pluck('id')->all();

        if ($stopIds !== [] && DB::table('transport_allocations')->count() < 8) {
            foreach (array_slice($this->enrollmentIds, 0, 8) as $i => $enrollmentId) {
                if (DB::table('transport_allocations')->where('enrollment_id', $enrollmentId)->exists()) {
                    continue;
                }

                $route = $routeIds[$i % count($routeIds)];
                $stop = DB::table('transport_stops')->where('route_id', $route)->value('id');

                try {
                    app(AllocateTransport::class)->handle(
                        $enrollmentId, $route, (int) $stop, TransportDirection::Both,
                        Carbon::parse('2026-09-10'), $actor,
                    );
                } catch (Throwable) {
                    // best effort per student
                }
            }
        }

        // Hostels: 1-2 hostels, rooms/beds, allocations.
        $hostelIds = [];

        foreach ([
            ['code' => 'HST-B', 'name' => 'Boys Hostel', 'gender' => 'boys'],
            ['code' => 'HST-G', 'name' => 'Girls Hostel', 'gender' => 'girls'],
        ] as $h) {
            $existing = DB::table('hostels')->where('code', $h['code'])->value('id');

            if (is_numeric($existing)) {
                $hostelIds[] = (int) $existing;

                continue;
            }

            $hostel = app(SaveHostel::class)->handle(null, [
                'code' => $h['code'], 'name' => $h['name'], 'gender' => $h['gender'], 'is_active' => true,
            ], $actor);
            $hostelIds[] = (int) $hostel->id;
        }

        $bedIds = [];

        foreach ($hostelIds as $hostelId) {
            for ($r = 1; $r <= 2; $r++) {
                $roomName = 'Room '.$r;
                $roomId = DB::table('hostel_rooms')->where('hostel_id', $hostelId)->where('name', $roomName)->value('id');

                if (! is_numeric($roomId)) {
                    $room = app(SaveRoom::class)->handle(null, [
                        'hostel_id' => $hostelId, 'name' => $roomName, 'capacity' => 4,
                    ], $actor);
                    $roomId = (int) $room->id;

                    $beds = app(SaveBeds::class)->handle($roomId, ['A', 'B', 'C', 'D'], $actor);

                    foreach ($beds as $bed) {
                        $bedIds[] = (int) $bed->id;
                    }
                } else {
                    foreach (DB::table('hostel_beds')->where('room_id', $roomId)->pluck('id') as $id) {
                        $bedIds[] = (int) $id;
                    }
                }
            }
        }

        if ($bedIds !== [] && DB::table('hostel_allocations')->count() < 6) {
            $slice = array_slice($this->enrollmentIds, 20, 6);

            foreach ($slice as $i => $enrollmentId) {
                if (DB::table('hostel_allocations')->where('enrollment_id', $enrollmentId)->exists()) {
                    continue;
                }

                try {
                    app(AllocateBed::class)->handle($enrollmentId, $bedIds[$i % count($bedIds)], Carbon::parse('2026-09-10'), $actor);
                } catch (Throwable) {
                }
            }
        }

        // Medical consultations.
        $studentIds = DB::table('students')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $severities = [ConsultationSeverity::Low, ConsultationSeverity::Moderate, ConsultationSeverity::High];
        $outcomes = [ConsultationOutcome::ReturnedToClass, ConsultationOutcome::SentHome, ConsultationOutcome::Referred];

        if (DB::table('medical_consultations')->count() < 8) {
            for ($i = 0; $i < 8; $i++) {
                app(RecordConsultation::class)->handle(
                    studentId: $studentIds[$i % count($studentIds)],
                    enrollmentId: null,
                    visitedAt: Carbon::parse('2026-10-0'.(1 + $i % 9)),
                    presentingComplaint: ['Headache', 'Stomach ache', 'Fever', 'Minor cut'][$i % 4],
                    diagnosis: null,
                    treatment: 'Rest and observation',
                    severity: $severities[$i % 3],
                    outcome: $outcomes[$i % 3],
                    actor: $actor,
                );
            }
        }

        // Visitor logs.
        if (DB::table('visitor_logs')->count() < 8) {
            for ($i = 0; $i < 8; $i++) {
                app(CheckInVisitor::class)->handle(
                    visitorName: 'Visitor '.($i + 1),
                    phone: sprintf('+237 6%08d', 60000000 + $i),
                    idDocumentRef: null,
                    purpose: ['Parent meeting', 'Delivery', 'Maintenance', 'Inspection'][$i % 4],
                    hostType: VisitorHostType::Office,
                    hostId: null,
                    badgeNo: 'BADGE-'.($i + 1),
                    checkedInAt: Carbon::parse('2026-10-0'.(1 + $i % 9).' 09:00:00'),
                    actor: $actor,
                );
            }
        }

        // Insurance.
        $feeItemId = DB::table('fee_items')->value('id');
        $policyId = DB::table('insurance_policies')->where('policy_no', 'POL-2026-001')->value('id');

        if (! is_numeric($policyId)) {
            $policy = app(SavePolicy::class)->handle(null, [
                'provider' => 'CamInsure SA',
                'policy_no' => 'POL-2026-001',
                'cover_type' => 'student',
                'coverage_start' => '2026-09-01',
                'coverage_end' => '2027-08-31',
                'premium_per_student' => 3_000,
                'academic_year_id' => $this->academicYearId,
                'fee_item_id' => $feeItemId,
                'status' => 'active',
            ], $actor);
            $policyId = (int) $policy->id;
        }

        $policyId = (int) $policyId;

        app(EnrollStudentsInPolicy::class)->handle(
            $policyId, array_slice($this->enrollmentIds, 0, 30), Carbon::parse('2026-09-15'), $actor,
        );

        $coverIds = DB::table('student_insurances')->where('policy_id', $policyId)->limit(2)->pluck('id')->all();

        if (DB::table('insurance_claims')->count() < 2) {
            foreach ($coverIds as $coverId) {
                app(RecordClaim::class)->handle(
                    policyId: $policyId,
                    incidentDate: Carbon::parse('2026-10-05'),
                    description: 'Minor playground injury',
                    amountClaimed: 15_000,
                    actor: $actor,
                    studentInsuranceId: (int) $coverId,
                );
            }
        }

        // Discipline cases.
        $categoryId = DB::table('discipline_categories')->where('name', 'Late arrival')->value('id');

        if (! is_numeric($categoryId)) {
            $categoryId = DB::table('discipline_categories')->insertGetId([
                'name' => 'Late arrival', 'name_fr' => 'Retard', 'severity' => 1,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::table('discipline_cases')->count() < 4) {
            for ($i = 0; $i < 4; $i++) {
                $case = app(OpenDisciplineCase::class)->handle(
                    studentId: $studentIds[($i + 5) % count($studentIds)],
                    categoryId: (int) $categoryId,
                    occurredOn: '2026-08-0'.(1 + $i),
                    description: 'Repeated late arrival to morning assembly.',
                    visibility: DisciplineVisibility::Internal,
                );

                if ($i < 2) {
                    app(ResolveDisciplineCase::class)->handle((int) $case->id, DisciplineCaseStatus::Resolved, 'Warned verbally.');
                }
            }
        }
    }

    // ── Assets ─────────────────────────────────────────────────────────

    private function seedAssets(): void
    {
        $actor = $this->actor();

        $categoryId = DB::table('asset_categories')->where('code', 'ICT')->value('id');

        if (! is_numeric($categoryId)) {
            $category = app(CreateAssetCategory::class)->handle(null, [
                'code' => 'ICT',
                'name' => 'ICT Equipment',
                'name_fr' => 'Materiel informatique',
                'asset_account_id' => $this->accountId('2442'),
                'accumulated_depreciation_account_id' => $this->accountId('28'),
                'disposal_nbv_account_id' => $this->accountId('812'),
                'disposal_proceeds_account_id' => $this->accountId('822'),
                'in_progress_account_id' => $this->accountId('249'),
                'depreciation_method' => 'straight_line',
                'useful_life_months' => 60,
                'default_residual_rate_bp' => 0,
                'prorata_convention' => 'monthly',
                'below_threshold_behaviour' => 'expense_only',
            ], $actor);
            $categoryId = (int) $category->id;
        }

        $categoryId = (int) $categoryId;

        // A category created by an earlier partial run may predate the
        // prorata_convention fix; backfill it directly (reference data, no
        // posted assets exist yet to freeze it).
        DB::table('asset_categories')
            ->where('id', $categoryId)
            ->whereNull('prorata_convention')
            ->update(['prorata_convention' => 'monthly', 'updated_at' => now()]);

        $existingCount = DB::table('assets')->where('asset_category_id', $categoryId)->count();
        $toCreate = max(0, 18 - $existingCount);
        $fiscalYearId = $this->fiscalYearId;
        $academicYearId = $this->academicYearId;

        for ($i = 0; $i < $toCreate; $i++) {
            $asset = app(RegisterAsset::class)->handle([
                'asset_category_id' => $categoryId,
                'name' => 'Desktop Computer #'.($existingCount + $i + 1),
                'acquisition_type' => 'purchase',
                'acquisition_cost' => 350_000,
                'acquisition_date' => '2026-09-05',
                'fiscal_year_id' => $fiscalYearId,
                'academic_year_id' => $academicYearId,
                'idempotency_key' => 'demo-asset-'.($existingCount + $i + 1),
            ], $actor);

            // Commission most of them (in-service), leave a couple in_progress/under maintenance.
            if ($i % 6 !== 5) {
                try {
                    app(CommissionAsset::class)->handle((int) $asset->id, '2026-09-06', $actor);
                } catch (Throwable) {
                }
            }
        }

        if ($toCreate > 0) {
            // Mark one commissioned asset as under maintenance for demo variety.
            $inService = DB::table('assets')->where('asset_category_id', $categoryId)->where('status', 'in_service')->value('id');

            if (is_numeric($inService)) {
                DB::table('assets')->where('id', $inService)->update(['status' => 'under_maintenance', 'updated_at' => now()]);
            }
        }

        if (DB::table('depreciation_runs')->where('fiscal_year_id', $fiscalYearId)->doesntExist()) {
            app(RunDepreciation::class)->handle($fiscalYearId, 1, $actor, 'demo-depreciation-run-1');
        }
    }

    // ── Inventory ──────────────────────────────────────────────────────

    private function seedInventory(): void
    {
        $actor = $this->actor();
        $this->ensurePostingRule('demo_inventory_received', PostingEvent::InventoryReceivedIntoStock, [
            ['path' => 'movement.stock_account_id', 'sign' => LineSign::Debit, 'expr' => 'movement.amount', 'label' => 'Stock in'],
            ['path' => 'movement.variation_account_id', 'sign' => LineSign::Credit, 'expr' => 'movement.amount', 'label' => 'Variation', 'balancing' => true],
        ]);
        $this->ensurePostingRule('demo_inventory_issued', PostingEvent::InventoryIssued, [
            ['path' => 'movement.variation_account_id', 'sign' => LineSign::Debit, 'expr' => 'movement.amount', 'label' => 'Variation'],
            ['path' => 'movement.stock_account_id', 'sign' => LineSign::Credit, 'expr' => 'movement.amount', 'label' => 'Stock out', 'balancing' => true],
        ]);

        $unitId = DB::table('units_of_measure')->where('code', 'PCS')->value('id');

        if (! is_numeric($unitId)) {
            $unitId = DB::table('units_of_measure')->insertGetId([
                'code' => 'PCS', 'name' => 'Piece', 'name_fr' => 'Piece', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $categoryId = DB::table('item_categories')->where('code', 'CONS')->value('id');

        if (! is_numeric($categoryId)) {
            $categoryId = DB::table('item_categories')->insertGetId([
                'code' => 'CONS', 'name' => 'Consumable Supplies', 'name_fr' => 'Fournitures consommables',
                'purchase_account_id' => $this->accountId('604'),
                'stock_account_id' => $this->accountId('33'),
                'variation_account_id' => $this->accountId('6033'),
                'cost_of_sales_uses_variation' => true,
                'is_archived' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $categoryId = (int) $categoryId;
        $unitId = (int) $unitId;

        $names = [
            'Whiteboard Markers', 'A4 Paper Ream', 'Chalk Box', 'Exercise Books',
            'Printer Ink Cartridge', 'Stapler', 'Broom', 'Detergent', 'First Aid Kit',
            'Football', 'Basketball', 'Cleaning Gloves',
        ];

        $itemIds = [];

        foreach ($names as $i => $name) {
            $code = sprintf('ITM%04d', $i + 1);
            $id = DB::table('items')->where('item_code', $code)->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('items')->insertGetId([
                    'item_code' => $code, 'name' => $name, 'item_category_id' => $categoryId,
                    'item_type' => 'consumable', 'unit_of_measure_id' => $unitId,
                    'is_stock_tracked' => true, 'reorder_level' => 10, 'reorder_quantity' => 50,
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $itemIds[] = (int) $id;
        }

        $locationIds = [];

        foreach ([
            ['code' => 'STORE-A', 'name' => 'Main Store', 'type' => 'store'],
            ['code' => 'STORE-LAB', 'name' => 'Science Lab Store', 'type' => 'lab'],
            ['code' => 'STORE-AV', 'name' => 'AV Room', 'type' => 'av_room'],
        ] as $loc) {
            $id = DB::table('store_locations')->where('code', $loc['code'])->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('store_locations')->insertGetId([
                    'code' => $loc['code'], 'name' => $loc['name'], 'type' => $loc['type'],
                    'is_sellable_point' => false, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $locationIds[] = (int) $id;
        }

        $mainStore = $locationIds[0];

        foreach ($itemIds as $i => $itemId) {
            if (DB::table('stock_movements')->where('item_id', $itemId)->where('store_location_id', $mainStore)->exists()) {
                continue;
            }

            try {
                app(ReceiveStock::class)->handle([
                    'item_id' => $itemId,
                    'store_location_id' => $mainStore,
                    'quantity' => '100.000',
                    'total_cost' => 50_000,
                    'moved_on' => '2026-09-10',
                    'fiscal_year_id' => $this->fiscalYearId,
                    'academic_year_id' => $this->academicYearId,
                    'idempotency_key' => 'demo-receipt-'.$itemId,
                ], $actor);
            } catch (Throwable) {
            }
        }

        try {
            app(IssueStock::class)->handle([
                'store_location_id' => $mainStore,
                'lines' => [
                    ['item_id' => $itemIds[0], 'quantity' => '10.000'],
                    ['item_id' => $itemIds[1], 'quantity' => '5.000'],
                ],
                'issued_on' => '2026-09-20',
                'fiscal_year_id' => $this->fiscalYearId,
                'academic_year_id' => $this->academicYearId,
                'idempotency_key' => 'demo-issue-1',
            ], $actor);
        } catch (Throwable) {
        }

        if (DB::table('stock_takes')->where('store_location_id', $mainStore)->doesntExist()) {
            try {
                app(StartStockTake::class)->handle([
                    'store_location_id' => $mainStore,
                    'count_date' => '2026-10-15',
                    'fiscal_year_id' => $this->fiscalYearId,
                    'academic_year_id' => $this->academicYearId,
                ], $actor);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  list<array{path?: string, code?: string, sign: LineSign, expr: string, label: string, balancing?: bool}>  $lines
     */
    private function ensurePostingRule(string $ruleCode, PostingEvent $event, array $lines): void
    {
        if (DB::table('posting_rules')->where('code', $ruleCode)->exists()) {
            return;
        }

        $journalId = DB::table('journals')->value('id');

        $built = [];

        foreach ($lines as $seq => $line) {
            $built[] = [
                'sequence' => $seq + 1,
                'account_source' => isset($line['path']) ? AccountSource::PayloadPath : AccountSource::Literal,
                'account_path' => $line['path'] ?? null,
                'account_code' => $line['code'] ?? null,
                'sign' => $line['sign'],
                'amount_expression' => $line['expr'],
                'is_balancing' => $line['balancing'] ?? false,
                'label_expression' => $line['label'],
            ];
        }

        app(SavePostingRule::class)->handle([
            'code' => $ruleCode,
            'event' => $event->value,
            'journal_id' => (int) $journalId,
            'label_expression' => $ruleCode,
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], $built, $this->actor());
    }

    // ── Library ────────────────────────────────────────────────────────

    private function seedLibrary(): void
    {
        $actor = $this->actor();

        $categoryId = DB::table('book_categories')->where('code', 'GEN')->value('id');

        if (! is_numeric($categoryId)) {
            $categoryId = DB::table('book_categories')->insertGetId([
                'code' => 'GEN', 'name' => 'General', 'name_fr' => 'Generale', 'is_archived' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $categoryId = (int) $categoryId;

        $shelfId = DB::table('shelf_locations')->value('id');

        if (! is_numeric($shelfId)) {
            $shelfId = DB::table('shelf_locations')->insertGetId([
                'code' => 'A1', 'name' => 'Shelf A1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $shelfId = (int) $shelfId;

        $classId = DB::table('membership_classes')->where('code', 'STD')->value('id');

        if (! is_numeric($classId)) {
            $classId = DB::table('membership_classes')->insertGetId([
                'code' => 'STD', 'name' => 'Standard', 'name_fr' => 'Standard',
                'loan_days' => 14,
                'max_concurrent_issues' => 3, 'can_borrow_reference' => false,
                'is_archived' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $classId = (int) $classId;

        $titles = [
            'Introduction to Biology', 'Cameroon History', 'English Grammar Essentials',
            'Algebra Fundamentals', 'World Geography', 'Physics for Secondary Schools',
            'Chemistry Basics', 'French Literature', 'Civic Education', 'Computer Science 101',
            'The River Between', 'Things Fall Apart', 'Long Walk to Freedom',
            'Economics Principles', 'Agricultural Science', 'Introduction to Philosophy',
            'Music Theory', 'Fine Art Techniques', 'Religious Studies Overview', 'Sports Science',
            'Modern Poetry', 'Statistics for Beginners', 'Cell Biology', 'Organic Chemistry',
        ];

        $bookIds = [];

        foreach ($titles as $i => $title) {
            $isbn = sprintf('978-99%02d-%03d-%01d', $i, $i * 3 % 900, $i % 9);
            $book = DB::table('books')->where('isbn', $isbn)->first();

            if ($book === null) {
                $created = app(RegisterBook::class)->handle(null, [
                    'isbn' => $isbn,
                    'title' => $title,
                    'author' => 'Author '.($i + 1),
                    'book_category_id' => $categoryId,
                    'replacement_cost' => 5_000,
                ], $actor);
                $bookId = (int) $created->id;

                app(AddBookCopies::class)->handle([
                    'book_id' => $bookId,
                    'shelf_location_id' => $shelfId,
                    'count' => 2,
                    'unit_cost' => 5_000,
                ], $actor);
            } else {
                $bookId = (int) $book->id;
            }

            $bookIds[] = $bookId;
        }

        // Members: students + staff.
        $memberIds = [];

        foreach (array_slice($this->enrollmentIds, 0, 15) as $enrollmentId) {
            $existing = DB::table('library_members')->where('enrollment_id', $enrollmentId)->value('id');

            if (is_numeric($existing)) {
                $memberIds[] = (int) $existing;

                continue;
            }

            try {
                $member = app(EnrollLibraryMember::class)->handle([
                    'member_type' => 'student',
                    'membership_class_id' => $classId,
                    'academic_year_id' => $this->academicYearId,
                    'joined_on' => '2026-09-15',
                    'enrollment_id' => $enrollmentId,
                    'idempotency_key' => 'demo-lm-'.$enrollmentId,
                ], $actor);
                $memberIds[] = (int) $member->id;
            } catch (Throwable) {
            }
        }

        foreach (array_slice($this->staffIds, 0, 5) as $staffId) {
            $existing = DB::table('library_members')->where('staff_member_id', $staffId)->value('id');

            if (is_numeric($existing)) {
                $memberIds[] = (int) $existing;

                continue;
            }

            try {
                $member = app(EnrollLibraryMember::class)->handle([
                    'member_type' => 'staff',
                    'membership_class_id' => $classId,
                    'academic_year_id' => $this->academicYearId,
                    'joined_on' => '2026-09-15',
                    'staff_member_id' => $staffId,
                    'idempotency_key' => 'demo-lm-staff-'.$staffId,
                ], $actor);
                $memberIds[] = (int) $member->id;
            } catch (Throwable) {
            }
        }

        // Circulation: issue some, return some.
        $copyIds = DB::table('book_copies')->whereIn('book_id', $bookIds)->where('status', 'available')->limit(10)->pluck('id')->all();

        foreach ($copyIds as $i => $copyId) {
            if (! isset($memberIds[$i])) {
                continue;
            }

            try {
                $issue = app(IssueBook::class)->handle([
                    'book_copy_id' => $copyId,
                    'library_member_id' => $memberIds[$i],
                    'issued_on' => '2026-10-01',
                    'idempotency_key' => 'demo-issue-'.$copyId,
                ], $actor);

                if ($i % 2 === 0) {
                    app(ReturnBook::class)->handle([
                        'issue_id' => $issue->id,
                        'returned_on' => '2026-10-10',
                        'condition' => 'good',
                    ], $actor);
                }
            } catch (Throwable) {
            }
        }

        // A couple of staff fines (payroll-deduction route, no fee item needed).
        if (count($memberIds) > 15) {
            foreach (array_slice($memberIds, -2) as $memberId) {
                try {
                    app(LevyFine::class)->handle([
                        'library_member_id' => $memberId,
                        'fine_type' => 'damage',
                        'amount' => 2_000,
                        'assessed_on' => '2026-10-12',
                    ], $actor);
                } catch (Throwable) {
                }
            }
        }
    }

    // ── HR ─────────────────────────────────────────────────────────────

    private function seedHr(): void
    {
        $actor = $this->actor();

        $departmentId = DB::table('departments')->value('id');

        if (! is_numeric($departmentId)) {
            $departmentId = DB::table('departments')->insertGetId([
                'code' => 'D-GEN', 'name' => 'General Department', 'name_fr' => 'Departement general',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $teachingPositionId = DB::table('positions')->where('code', 'TCH')->value('id');

        if (! is_numeric($teachingPositionId)) {
            $teachingPositionId = DB::table('positions')->insertGetId([
                'code' => 'TCH', 'name' => 'Teacher', 'name_fr' => 'Enseignant',
                'is_teaching' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $adminPositionId = DB::table('positions')->where('code', 'ADM')->value('id');

        if (! is_numeric($adminPositionId)) {
            $adminPositionId = DB::table('positions')->insertGetId([
                'code' => 'ADM', 'name' => 'Administrator', 'name_fr' => 'Administrateur',
                'is_teaching' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $departmentId = (int) $departmentId;
        $teachingPositionId = (int) $teachingPositionId;
        $adminPositionId = (int) $adminPositionId;

        $contractIds = [];

        foreach ($this->staffIds as $i => $staffId) {
            $existing = DB::table('staff_contracts')->where('staff_member_id', $staffId)->value('id');

            if (is_numeric($existing)) {
                $contractIds[] = (int) $existing;

                continue;
            }

            try {
                $contract = app(OpenStaffContract::class)->handle(
                    staffMemberId: $staffId,
                    contractRole: 'teaching',
                    contractType: ContractType::Cdi,
                    workingTime: WorkingTime::FullTime,
                    departmentId: $departmentId,
                    positionId: $i % 4 === 0 ? $adminPositionId : $teachingPositionId,
                    startsOn: '2026-09-01',
                );
                $contractIds[] = (int) $contract->id;
            } catch (Throwable) {
            }
        }

        // Leave requests: a few pending, a few approved.
        $leaveTypeCode = DB::table('leave_types')->where('is_active', true)->value('code') ?? 'conge_annuel';

        foreach (array_slice($contractIds, 0, 5) as $i => $contractId) {
            if (DB::table('leave_requests')->where('staff_contract_id', $contractId)->exists()) {
                continue;
            }

            try {
                $request = app(RequestLeave::class)->handle(
                    staffContractId: $contractId,
                    leaveTypeCode: (string) $leaveTypeCode,
                    startsOn: '2026-11-0'.(1 + $i),
                    endsOn: '2026-11-0'.(3 + $i),
                    workingDays: '3',
                    actor: $actor,
                );

                if ($i % 2 === 0) {
                    app(ApproveLeave::class)->approve((int) $request->id, $actor);
                }
            } catch (Throwable) {
            }
        }
    }

    // ── Payroll ────────────────────────────────────────────────────────

    private function seedPayroll(): void
    {
        $actor = $this->actor();

        $profileId = DB::table('employer_profiles')->value('id');

        if (! is_numeric($profileId)) {
            $documentId = 1; // no documents module wired yet; a bare reference id.

            app(ConfigureEmployerProfile::class)->handle(
                cnpsEmployerNumber: 'CNPS-DEMO-0001',
                dipeNumber: 'DIPE-DEMO-0001',
                niu: 'M012600000001X',
                tdlCommuneId: (int) (DB::table('communes')->value('id') ?: $this->firstCommune()),
                cnpsRegime: CnpsRegime::EnseignementPrive,
                rpRiskClass: 'A',
                cnpsNotificationDocumentId: $documentId,
                cnpsNotificationReference: 'NOTIF-DEMO-0001',
                effectiveFrom: '2026-01-01',
                regimeConfirmed: true,
                riskClassConfirmed: true,
                actor: $actor,
            );
        }

        // A calculated/approved run needs liability accounts wired on every
        // statutory component and a payroll.approved posting rule; attempt
        // it best-effort and leave the profile configured either way.
        foreach (DB::table('payroll_components')->whereIn('type', ['employee_deduction', 'employer_charge'])->get() as $component) {
            if ($component->liability_account_id !== null) {
                continue;
            }

            DB::table('payroll_components')->where('id', $component->id)->update([
                'liability_account_id' => $this->anyPostableAccount(),
                'updated_at' => now(),
            ]);
        }

        DB::table('payroll_components')->where('type', 'employer_charge')->whereNull('expense_account_id')->update([
            'expense_account_id' => $this->anyPostableAccount(),
            'updated_at' => now(),
        ]);

        $this->ensureDemoStatutoryRates();
        $this->ensureStaffCnpsNumbers();
        $this->ensureStaffCompensations();
        $this->ensurePayrollPostingRule();

        $calculator = User::query()->firstOrCreate(
            ['email' => 'demo.payroll1@opeschool.test'],
            ['name' => 'Demo Payroll Officer 1', 'password' => Str::random(32)],
        );

        if (! $calculator->hasRole(Role::PayrollOfficer->value)) {
            $calculator->assignRole(Role::PayrollOfficer->value);
        }

        $approver = User::query()->firstOrCreate(
            ['email' => 'demo.payroll2@opeschool.test'],
            ['name' => 'Demo Payroll Officer 2', 'password' => Str::random(32)],
        );

        if (! $approver->hasRole(Role::PayrollOfficer->value)) {
            $approver->assignRole(Role::PayrollOfficer->value);
        }

        // The PayrollOfficer role's default permission set does not include
        // payroll.approve (05-hr-payroll 8.7 segregation of duties expects
        // approval to sit with a distinct role in a real deployment); grant
        // it directly to this demo approver.
        foreach (['payroll.approve', 'ledger.post', 'ledger.configure'] as $extra) {
            \Spatie\Permission\Models\Permission::findOrCreate($extra, 'web');

            if (! $approver->can($extra)) {
                $approver->givePermissionTo($extra);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $calculator = $calculator->fresh() ?? $calculator;
        $approver = $approver->fresh() ?? $approver;

        $admin = Auth::user();

        try {
            Auth::login($calculator->fresh() ?? $calculator);
            $run = app(CalculatePayrollRun::class)->handle('2026-10-01', RunType::Regular, $calculator->toAuditActor(), 'demo-payroll-2026-10');

            Auth::login($approver->fresh() ?? $approver);

            if ($run->status->value === 'calculated') {
                app(ApprovePayrollRun::class)->handle((int) $run->id, $approver->toAuditActor());
            }
        } catch (Throwable $e) {
            // The employer profile and components are configured either
            // way; a full calculated/approved run additionally needs a
            // complete statutory-rate table (IRPP brackets, RAV/TDL bands,
            // per-staff CNPS numbers) that this demo dataset does not
            // attempt to fabricate. Report and move on.
            $this->command?->warn('[Payroll] run not calculated: '.$e->getMessage());
        } finally {
            Auth::login($admin);
        }
    }

    /**
     * The existing statutory_rates rows are deliberately empty placeholders
     * (00-core §16: nothing statutory is seeded). A calculated/approved demo
     * run needs a complete, internally-consistent set, so this inserts a
     * second, VERIFIED batch dated 2026-01-01 - reference figures for demo
     * purposes only, clearly labelled as such in source_citation.
     */
    private function ensureDemoStatutoryRates(): void
    {
        if (DB::table('statutory_rates')->where('source_citation', 'like', 'DEMO DATA%')->exists()) {
            return;
        }

        $base = [
            'effective_from' => '2026-01-01',
            'is_verified' => true,
            'source_citation' => 'DEMO DATA - reference figures for demo purposes only, not a real statutory table',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $rows = [
            $base + ['code' => 'PVID', 'label' => 'PVID (demo)', 'shape' => 'percentage', 'basis' => 'cnps_capped',
                'employee_rate_bp' => 4_200, 'employer_rate_bp' => 4_200, 'ceiling_amount' => 750_000],
            $base + ['code' => 'PF', 'label' => 'PF (demo)', 'shape' => 'percentage', 'basis' => 'cnps_capped',
                'employer_rate_bp' => 3_700, 'cnps_regime' => 'enseignement_prive'],
            $base + ['code' => 'RP', 'label' => 'RP (demo)', 'shape' => 'percentage', 'basis' => 'cnps_uncapped',
                'employer_rate_bp' => 1_750, 'risk_class' => 'A'],
            $base + ['code' => 'IRPP', 'label' => 'IRPP bracket 1 (demo)', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
                'bracket_basis' => 'annual', 'band_from' => 0, 'band_to' => 2_000_000, 'employee_rate_bp' => 10_000],
            $base + ['code' => 'IRPP', 'label' => 'IRPP bracket 2 (demo)', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
                'bracket_basis' => 'annual', 'band_from' => 2_000_000, 'band_to' => 3_000_000, 'employee_rate_bp' => 15_000],
            $base + ['code' => 'IRPP', 'label' => 'IRPP bracket 3 (demo)', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
                'bracket_basis' => 'annual', 'band_from' => 3_000_000, 'band_to' => 5_000_000, 'employee_rate_bp' => 25_000],
            $base + ['code' => 'IRPP', 'label' => 'IRPP bracket 4 (demo)', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
                'bracket_basis' => 'annual', 'band_from' => 5_000_000, 'band_to' => null, 'employee_rate_bp' => 35_000],
            $base + ['code' => 'CAC', 'label' => 'CAC (demo)', 'shape' => 'percentage', 'basis' => 'irpp_amount', 'employee_rate_bp' => 10_000],
            $base + ['code' => 'CFC', 'label' => 'CFC (demo)', 'shape' => 'percentage', 'basis' => 'gross',
                'employee_rate_bp' => 1_000, 'employer_rate_bp' => 1_500],
            $base + ['code' => 'FNE', 'label' => 'FNE (demo)', 'shape' => 'percentage', 'basis' => 'gross', 'employer_rate_bp' => 1_000],
            $base + ['code' => 'RAV', 'label' => 'RAV (demo)', 'shape' => 'flat_band', 'basis' => 'gross',
                'flat_amount' => 0, 'band_from' => 0, 'band_to' => null],
            $base + ['code' => 'TDL', 'label' => 'TDL (demo)', 'shape' => 'flat_band', 'basis' => 'basic',
                'flat_amount' => 0, 'band_from' => 0, 'band_to' => null],
        ];

        // Insert one at a time: the rows carry different key sets and a
        // single bulk insert() requires identical columns across all rows.
        foreach ($rows as $row) {
            DB::table('statutory_rates')->insert($row);
        }
    }

    private function ensureStaffCnpsNumbers(): void
    {
        foreach (\App\Modules\HR\Models\StaffMember::query()->whereNull('cnps_number')->get() as $staff) {
            $staff->forceFill([
                'cnps_number' => 'CNPS-'.$staff->id,
                'cnps_number_blind_index' => \App\Modules\HR\Models\StaffMember::blindIndexFor('CNPS-'.$staff->id),
                'cnps_registration_status' => 'registered',
                'cnps_registered_on' => '2026-09-01',
            ])->save();
        }
    }

    private function ensureStaffCompensations(): void
    {
        $granter = Auth::user();

        foreach (DB::table('staff_contracts')->pluck('id') as $contractId) {
            if (DB::table('staff_compensations')->where('staff_contract_id', $contractId)->where('component_code', 'BASIC')->exists()) {
                continue;
            }

            DB::table('staff_compensations')->insert([
                'staff_contract_id' => $contractId,
                'component_code' => 'BASIC',
                'amount' => 250_000,
                'rate_bp' => null,
                'effective_from' => '2026-09-01',
                'effective_to' => null,
                'retroactive_from' => null,
                'granted_by' => $granter->id,
                'grant_reason' => 'Demo data hiring grant',
                'document_id' => null,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function firstCommune(): int
    {
        $id = DB::table('communes')->insertGetId([
            'name' => 'Douala', 'region' => 'Littoral', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $id;
    }

    private function anyPostableAccount(): int
    {
        return (int) DB::table('chart_of_accounts')->where('is_postable', true)->value('id');
    }

    /**
     * SYSCOHADA 66 "Charges de personnel" - the DEBIT side of a payroll
     * accrual.
     *
     * Without this the payroll rule fell back to `anyPostableAccount()`,
     * which returned whichever postable row sorted first - a CLASS 9
     * (hors-bilan) account. The trial balance still tied, because class 9
     * balanced against itself, but the bilan was out by the whole payroll:
     * a real liability on 422 with no charge behind it. That is precisely
     * the error a trial balance cannot catch and a balance sheet can, so it
     * is fixed at the source rather than patched in the statement.
     */
    private function demoPersonnelChargeAccount(): int
    {
        $existing = DB::table('chart_of_accounts')->where('code', '661')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        // account_class/depth are generated from `code`, so 661 needs its
        // parent chain: 6 (depth 1) -> 66 (depth 2) -> 661.
        $level1 = DB::table('chart_of_accounts')->where('code', '6')->value('id');

        $level2 = DB::table('chart_of_accounts')->where('code', '66')->value('id');

        if (! is_numeric($level2)) {
            $level2 = DB::table('chart_of_accounts')->insertGetId([
                'code' => '66', 'name' => 'Personnel charges', 'name_fr' => 'Charges de personnel',
                'parent_id' => $level1, 'type' => 'expense', 'normal_balance' => 'debit',
                'is_postable' => false, 'is_archived' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => '661',
            'name' => 'Staff remuneration expense',
            'name_fr' => 'Remunerations directes versees au personnel',
            'parent_id' => (int) $level2,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A collective 42x-style account accepting a `staff` partner, for the net-pay posting line. */
    private function demoStaffPayableAccount(): int
    {
        $existing = DB::table('chart_of_accounts')->where('code', '422')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        // account_class/depth are generated from `code`, so a depth-3 leaf
        // needs a real parent chain: 4 (depth 1) -> 42 (depth 2) -> 422.
        $level1 = DB::table('chart_of_accounts')->where('code', '4')->value('id');

        $level2 = DB::table('chart_of_accounts')->where('code', '42')->value('id');

        if (! is_numeric($level2)) {
            $level2 = DB::table('chart_of_accounts')->insertGetId([
                'code' => '42', 'name' => 'Personnel (demo)', 'name_fr' => 'Personnel (demo)',
                'parent_id' => $level1, 'type' => 'liability', 'normal_balance' => 'credit',
                'is_postable' => false, 'is_archived' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => '422',
            'name' => 'Staff remuneration payable (demo)',
            'name_fr' => 'Personnel, remunerations dues (demo)',
            'parent_id' => (int) $level2,
            'type' => 'liability',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'is_collective' => true,
            'requires_partner' => true,
            'allowed_partner_types' => json_encode(['staff']),
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePayrollPostingRule(): void
    {
        if (DB::table('posting_rules')->where('code', 'demo_payroll_approved')->exists()) {
            return;
        }

        $journalId = DB::table('journals')->value('id');
        $payableAccountId = $this->demoStaffPayableAccount();
        // A payroll accrual is Dr charge (class 6) / Cr liability (class 42).
        // Never `anyPostableAccount()` - that silently debited a class-9
        // hors-bilan account and left the bilan out by the whole payroll.
        $expenseAccountId = $this->demoPersonnelChargeAccount();

        app(SavePostingRule::class)->handle([
            'code' => 'demo_payroll_approved',
            'event' => PostingEvent::PayrollApproved->value,
            'journal_id' => (int) $journalId,
            'label_expression' => 'Paie {run.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.liability_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'iterates_over' => 'run.remittances',
                'skip_if_zero' => true,
                'label_expression' => 'Retenue {item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => DB::table('chart_of_accounts')->where('id', $payableAccountId)->value('code'),
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.net',
                'iterates_over' => 'run.items',
                'partner_source' => 'item.staff_partner',
                'skip_if_zero' => true,
                'label_expression' => 'Net a payer {item.label}',
            ],
            [
                'sequence' => 3,
                'account_source' => AccountSource::Literal,
                'account_code' => DB::table('chart_of_accounts')->where('id', $expenseAccountId)->value('code'),
                'sign' => LineSign::Debit,
                'amount_expression' => 'run.total_gross + run.total_employer_charges',
                'is_balancing' => true,
                'label_expression' => 'Charges de personnel {run.reference}',
            ],
        ], $this->actor());
    }

    // ── Procurement ────────────────────────────────────────────────────

    private function seedProcurement(): void
    {
        $actor = $this->actor();
        $payableAccountId = $this->accountId('401');
        $expenseAccountId = $this->anyPostableAccount();

        $supplierNames = [
            'Bureau Plus Cameroun', 'Ecole Fournitures SARL', 'InfoTech Solutions',
            'Batisseurs du Littoral', 'Fournitures Scolaires Excellence',
            'Electro Service Douala', 'Papeterie Moderne',
        ];

        $supplierIds = [];

        foreach ($supplierNames as $i => $name) {
            $existing = DB::table('suppliers')->where('name', $name)->value('id');

            if (is_numeric($existing)) {
                $supplierIds[] = (int) $existing;

                continue;
            }

            try {
                $supplier = app(SaveSupplier::class)->handle([
                    'name' => $name,
                    'supplier_type' => 'company',
                    'payable_account_id' => $payableAccountId,
                    'default_expense_account_id' => $expenseAccountId,
                    'payment_terms_days' => 30,
                    'currency' => 'XAF',
                    'phone' => sprintf('+237 6%08d', 70100000 + $i),
                    'city' => 'Douala',
                    'country' => 'CM',
                ], $actor);
                $supplierIds[] = (int) $supplier->id;
            } catch (Throwable) {
            }
        }

        if ($supplierIds === []) {
            return;
        }

        $bursar = User::query()->where('email', 'demo.bursar@opeschool.test')->first();
        $principal = Auth::user();

        $poIds = [];

        foreach (array_slice($supplierIds, 0, 5) as $i => $supplierId) {
            if (DB::table('purchase_orders')->where('supplier_id', $supplierId)->exists()) {
                $poIds[] = (int) DB::table('purchase_orders')->where('supplier_id', $supplierId)->value('id');

                continue;
            }

            try {
                Auth::login($bursar ?? $principal);
                $po = app(CreatePurchaseOrder::class)->handle([
                    'supplier_id' => $supplierId,
                    'order_date' => '2026-09-20',
                    'academic_year_id' => $this->academicYearId,
                    'fiscal_year_id' => $this->fiscalYearId,
                    'payable_account_id' => $payableAccountId,
                ], [
                    [
                        'description' => 'Office and classroom supplies',
                        'quantity' => '10.000',
                        'unit_price_ht' => 15_000,
                        'expense_account_id' => $expenseAccountId,
                    ],
                ], ($bursar ?? $principal)->toAuditActor());

                $poIds[] = (int) $po->id;

                // Vary statuses across the 5 orders: draft, approved, sent...
                if ($i > 0) {
                    Auth::login($principal);
                    app(ApprovePurchaseOrder::class)->handle((int) $po->id, $principal->toAuditActor());
                }

                if ($i > 2) {
                    Auth::login($bursar ?? $principal);
                    app(SendPurchaseOrder::class)->handle((int) $po->id, ($bursar ?? $principal)->toAuditActor());
                }
            } catch (Throwable) {
            } finally {
                Auth::login($principal);
            }
        }

        // Goods receipts against a couple of sent/approved POs.
        $receivable = DB::table('purchase_orders')->whereIn('id', $poIds)->whereIn('status', ['approved', 'sent'])->limit(2)->get();

        foreach ($receivable as $po) {
            if (DB::table('goods_receipts')->where('purchase_order_id', $po->id)->exists()) {
                continue;
            }

            $line = DB::table('purchase_order_lines')->where('purchase_order_id', $po->id)->first();

            try {
                app(SaveGoodsReceipt::class)->handle([
                    'supplier_id' => $po->supplier_id,
                    'purchase_order_id' => $po->id,
                    'received_on' => '2026-10-01',
                    'academic_year_id' => $this->academicYearId,
                    'fiscal_year_id' => $this->fiscalYearId,
                ], [
                    [
                        'qty_received' => $line->quantity ?? '10.000',
                        'purchase_order_line_id' => $line->id ?? null,
                        'description' => $line->description ?? 'Goods',
                    ],
                ], ($bursar ?? $principal)->toAuditActor());
            } catch (Throwable) {
            }
        }
    }

    // ── Tax ────────────────────────────────────────────────────────────

    private function seedTax(): void
    {
        $actor = $this->actor();

        if (DB::table('tax_codes')->where('code', 'TVA19')->doesntExist()) {
            app(ConfigureTaxCode::class)->handle(null, [
                'code' => 'TVA19',
                'name' => 'VAT standard rate',
                'name_fr' => 'TVA taux normal',
                'tax_type' => 'tva',
                'rate_bp' => 19_250,
                'direction' => 'both',
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ], $actor);
        }

        if (DB::table('tax_codes')->where('code', 'EXEMPT')->doesntExist()) {
            app(ConfigureTaxCode::class)->handle(null, [
                'code' => 'EXEMPT',
                'name' => 'VAT exempt',
                'name_fr' => 'Exonere de TVA',
                'tax_type' => 'tva',
                'rate_bp' => 0,
                'direction' => 'both',
                'effective_from' => '2026-01-01',
                'is_exempt' => true,
                'exemption_legal_ref' => 'CGI Art. 128',
                'is_active' => true,
            ], $actor);
        }

        if (DB::table('withholding_rules')->where('code', 'ART225')->doesntExist()) {
            app(ConfigureWithholdingRule::class)->handle(null, [
                'code' => 'ART225',
                'name' => 'Withholding on services (Art 225)',
                'name_fr' => 'Retenue sur prestations (Art 225)',
                'withholding_type' => 'air',
                'rate_bp' => 5_500,
                'applies_to' => 'services',
                'priority' => 100,
                'legal_ref' => 'CGI Art. 225',
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ], $actor);
        }
    }

    // ── Assessment ─────────────────────────────────────────────────────

    private function seedAssessment(): void
    {
        $actor = $this->actor();

        $frameworkId = DB::table('assessment_frameworks')->where('code', 'DEMO-STD')->value('id');

        if (! is_numeric($frameworkId)) {
            $framework = app(CreateFramework::class)->handle([
                'school_section_id' => $this->schoolSectionId,
                'academic_year_id' => $this->academicYearId,
                'code' => 'DEMO-STD',
                'name' => 'Standard Framework',
                'name_fr' => 'Cadre standard',
                'family' => 'D',
                'assessment_mode' => 'numeric',
                'max_score' => '20',
                'pass_score' => '10',
                'requires_per_lesson_attendance' => false,
                'is_default' => true,
                'is_active' => true,
            ], $actor);
            $frameworkId = (int) $framework->id;

            app(DefineComponents::class)->handle((int) $frameworkId, [
                ['code' => 'CA', 'name' => 'Continuous Assessment', 'name_fr' => 'Controle continu', 'max_score' => '10'],
                ['code' => 'EXAM', 'name' => 'Sequence Exam', 'name_fr' => 'Examen de sequence', 'max_score' => '10'],
            ], $actor);
        }

        $frameworkId = (int) $frameworkId;

        // DemoDataSeeder's subject_allocations all carry required_components
        // = [] (no framework existed yet when it ran), so nothing would ever
        // materialise into marks. Wire this framework's two components onto
        // a subset of allocations so OpenAssessmentPeriod below has
        // something to generate.
        $componentIds = DB::table('assessment_components')
            ->where('framework_id', $frameworkId)->pluck('id')->all();

        $allocationIdsToWire = DB::table('subject_allocations')
            ->where('academic_year_id', $this->academicYearId)
            ->whereRaw('JSON_LENGTH(required_components) = 0')
            ->limit(10)
            ->pluck('id');

        DB::table('subject_allocations')
            ->whereIn('id', $allocationIdsToWire)
            ->update(['required_components' => json_encode($componentIds), 'updated_at' => now()]);

        $yearId = DB::table('assessment_periods')->where('code', 'YEAR')->where('academic_year_id', $this->academicYearId)->value('id');

        if (! is_numeric($yearId)) {
            $yearId = DB::table('assessment_periods')->insertGetId([
                'academic_year_id' => $this->academicYearId, 'framework_id' => $frameworkId,
                'parent_id' => null, 'code' => 'YEAR', 'name' => 'Academic Year 2026/2027',
                'name_fr' => 'Annee scolaire 2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31',
                'weight' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $seq1Id = DB::table('assessment_periods')->where('code', 'SEQ1')->where('academic_year_id', $this->academicYearId)->value('id');

        if (! is_numeric($seq1Id)) {
            $seq1Id = DB::table('assessment_periods')->insertGetId([
                'academic_year_id' => $this->academicYearId, 'framework_id' => $frameworkId,
                'parent_id' => $yearId, 'code' => 'SEQ1', 'name' => 'Sequence 1',
                'name_fr' => 'Sequence 1', 'starts_on' => '2026-09-15', 'ends_on' => '2026-10-31',
                'weight' => 1, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $seq1Id = (int) $seq1Id;

        app(OpenAssessmentPeriod::class)->handle($seq1Id, $actor);

        // Schedule a few exams.
        $allocations = DB::table('subject_allocations')
            ->where('academic_year_id', $this->academicYearId)
            ->limit(5)->get(['id', 'class_level_id']);

        $classGroupId = DB::table('class_groups')->where('academic_year_id', $this->academicYearId)->value('id');

        foreach ($allocations as $i => $alloc) {
            if (DB::table('exams')->where('subject_allocation_id', $alloc->id)->where('assessment_period_id', $seq1Id)->exists()) {
                continue;
            }

            try {
                app(ScheduleExam::class)->handle(
                    examTypeId: 1,
                    assessmentPeriodId: $seq1Id,
                    subjectAllocationId: (int) $alloc->id,
                    classGroupId: (int) $classGroupId,
                    scheduledOn: '2026-10-'.sprintf('%02d', 15 + $i),
                    startsAt: '08:00',
                    durationMinutes: 60,
                    maxScore: '20',
                );
            } catch (Throwable) {
            }
        }

        // Enter a handful of marks.
        $marks = Mark::query()->where('assessment_period_id', $seq1Id)->limit(30)->get();
        $byAllocation = $marks->groupBy('subject_allocation_id');

        foreach ($byAllocation as $subjectAllocationId => $group) {
            $rows = [];

            foreach ($group->take(10) as $mark) {
                $rows[] = [
                    'mark_id' => (int) $mark->id,
                    'version' => $mark->version,
                    'state' => 'scored',
                    'score' => (string) random_int(4, 10),
                ];
            }

            if ($rows === []) {
                continue;
            }

            try {
                app(SaveMarkBatch::class)->handle((int) $subjectAllocationId, $seq1Id, $rows);
            } catch (Throwable) {
            }
        }
    }

    // ── Identity users ─────────────────────────────────────────────────

    private function seedUsers(): void
    {
        $admin = Auth::user();

        $users = [
            ['name' => 'Marie Teacher', 'email' => 'demo.teacher@opeschool.test', 'role' => Role::Teacher],
            ['name' => 'Paul Registrar', 'email' => 'demo.registrar@opeschool.test', 'role' => Role::Registrar],
            ['name' => 'Grace HrOfficer', 'email' => 'demo.hr@opeschool.test', 'role' => Role::HrOfficer],
            ['name' => 'Serge StoreKeeper', 'email' => 'demo.store@opeschool.test', 'role' => Role::StoreKeeper],
            ['name' => 'Aminatou Librarian', 'email' => 'demo.librarian@opeschool.test', 'role' => Role::Librarian],
        ];

        foreach ($users as $u) {
            if (User::query()->where('email', $u['email'])->exists()) {
                continue;
            }

            app(CreateUser::class)->handle($u['name'], $u['email'], $u['role'], Str::random(32), $admin);
        }
    }

    // ── Guardians portal ───────────────────────────────────────────────

    private function seedGuardianPortal(): void
    {
        $actor = $this->actor();

        $guardianIds = DB::table('guardians')
            ->whereNull('portal_user_id')
            ->where('is_archived', false)
            ->limit(6)
            ->pluck('id');

        foreach ($guardianIds as $i => $guardianId) {
            $email = sprintf('demo.guardian%d@opeschool.test', $guardianId);

            if (User::query()->where('email', $email)->exists()) {
                continue;
            }

            try {
                ['code' => $code] = app(IssuePortalInvitation::class)->handle(
                    PortalSubjectType::Guardian, (int) $guardianId, $actor,
                );

                app(ActivatePortalAccount::class)->handle(
                    $code,
                    'Guardian Portal User '.($i + 1),
                    $email,
                    Str::random(16).'Aa1!',
                );
            } catch (Throwable) {
            }
        }
    }

    // ── Staff portal ──────────────────────────────────────────────────

    private function seedStaffPortal(): void
    {
        $actor = Auth::user();

        if ($actor === null) {
            return;
        }

        if (User::query()->where('email', 'demo.staffportal@opeschool.test')->exists()) {
            return;
        }

        $staffId = DB::table('staff_members')->whereNull('portal_user_id')->orderBy('id')->value('id');

        if ($staffId === null) {
            return;
        }

        try {
            app(\App\Modules\HR\Actions\GrantStaffPortalAccess::class)->handle(
                (int) $staffId,
                'demo.staffportal@opeschool.test',
                $actor->toAuditActor(),
            );
        } catch (Throwable) {
        }
    }
}
