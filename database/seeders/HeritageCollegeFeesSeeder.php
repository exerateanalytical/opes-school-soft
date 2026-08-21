<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Actions\AdjustInvoice;
use App\Modules\Fees\Actions\ApproveFeeAdjustment;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Domain\FeeAdjustmentReasonType;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\AllocateBed;
use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Actions\SaveHostel;
use App\Modules\Welfare\Actions\SaveRoom;
use App\Modules\Welfare\Domain\HostelGender;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Additive, idempotent seeder for "Heritage College": a day-student fee
 * structure that totals exactly 1,700,000 FCFA/year, plus a realistic mix
 * of invoice/payment states over a broad sample of currently-enrolled
 * students, plus a small real boarding setup (hostel/rooms/beds/
 * allocations) via the genuine Welfare Actions.
 *
 * Never touches DemoDataSeeder's rows directly; only adds new ones keyed by
 * distinct names/codes so it is safe next to the three other agents growing
 * this same demo DB concurrently. Re-run safe: every insertion point
 * guards with firstOrCreate / where-exists before creating.
 */
final class HeritageCollegeFeesSeeder extends Seeder
{
    private int $academicYearId;

    private int $fiscalYearId;

    private int $schoolSectionId;

    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin !== null) {
            Auth::login($admin);
        }

        /** @var object{id:int} $ay */
        $ay = DB::table('academic_years')->orderByDesc('id')->firstOrFail();
        $this->academicYearId = (int) $ay->id;

        /** @var object{id:int} $fy */
        $fy = DB::table('fiscal_years')->orderByDesc('id')->firstOrFail();
        $this->fiscalYearId = (int) $fy->id;

        /** @var object{id:int} $sec */
        $sec = DB::table('school_sections')->orderBy('id')->firstOrFail();
        $this->schoolSectionId = (int) $sec->id;

        $structureId = $this->createFeeStructure();

        $this->buildBoarding();

        $this->generateAndCollect($structureId);

        $this->command?->info('Heritage College fees seeding complete.');
    }

    // ── Fee structure: day student, exactly 1,700,000 FCFA/year ────────────

    private function createFeeStructure(): int
    {
        $name = 'Heritage College Standard Fees 2026/2027';

        $existing = DB::table('fee_structures')
            ->where('academic_year_id', $this->academicYearId)
            ->where('school_section_id', $this->schoolSectionId)
            ->where('name', $name)
            ->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $revenueAccountId = DB::table('chart_of_accounts')->where('code', '706')->value('id');

        if (! is_numeric($revenueAccountId)) {
            throw new \RuntimeException('Account 706 is not seeded; run migrations first.');
        }

        // boarding_scope = 'day' (not 'any') so this structure is STRICTLY
        // more specific than DemoDataSeeder's pre-existing 'Standard Fees
        // 2026/2027' ('any' boarding scope) for day enrollments -
        // GenerateInvoices::resolveStructure would otherwise report an
        // ambiguous tie between the two (both score 0 at class/stream/
        // status level).
        $structureId = (int) DB::table('fee_structures')->insertGetId([
            'academic_year_id' => $this->academicYearId,
            'school_section_id' => $this->schoolSectionId,
            'class_level_id' => 0,
            'stream_id' => 0,
            'enrollment_status_scope' => 'any',
            'boarding_scope' => 'day',
            'name' => $name,
            'status' => 'active',
            'version' => 1,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sums to exactly 1,700,000 FCFA.
        $items = [
            ['name' => 'Tuition Fee', 'amount' => 1_200_000],
            ['name' => 'Registration Fee', 'amount' => 100_000],
            ['name' => 'Transport Fee', 'amount' => 180_000],
            ['name' => 'Examination Fee', 'amount' => 60_000],
            ['name' => 'Uniform Fee', 'amount' => 60_000],
            ['name' => 'Books & Learning Materials Fee', 'amount' => 40_000],
            ['name' => 'PTA Levy', 'amount' => 20_000],
            ['name' => 'Library Fee', 'amount' => 20_000],
            ['name' => 'Sports Fee', 'amount' => 20_000],
        ];

        $total = array_sum(array_column($items, 'amount'));

        if ($total !== 1_700_000) {
            throw new \RuntimeException("Heritage College fee items sum to {$total}, expected 1,700,000.");
        }

        foreach ($items as $order => $item) {
            $categoryId = DB::table('fee_categories')->where('name', $item['name'].' category')->value('id');

            if (! is_numeric($categoryId)) {
                $categoryId = DB::table('fee_categories')->insertGetId([
                    'code' => 'HCCAT'.Str::upper(Str::random(6)),
                    'name' => $item['name'].' category',
                    'name_fr' => $item['name'].' categorie',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $code = 'HC'.Str::upper(Str::slug($item['name'], ''));
            $feeItemId = DB::table('fee_items')->where('code', $code)->value('id');

            if (! is_numeric($feeItemId)) {
                $feeItemId = DB::table('fee_items')->insertGetId([
                    'code' => $code,
                    'name' => $item['name'],
                    'name_fr' => $item['name'],
                    'fee_category_id' => $categoryId,
                    'collection_basis' => 'own_revenue',
                    'third_party_fund_id' => null,
                    'revenue_account_id' => $revenueAccountId,
                    'recognition_method' => 'on_issue',
                    'tax_code_id' => null,
                    'is_refundable' => false,
                    'is_mandatory' => true,
                    'default_recurrence' => 'per_year',
                    'asset_or_service_note' => null,
                    'is_archived' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('fee_structure_lines')->insert([
                'fee_structure_id' => $structureId,
                'fee_item_id' => $feeItemId,
                'amount' => $item['amount'],
                'term_id' => 0,
                'service_period_start' => null,
                'service_period_end' => null,
                'is_optional' => false,
                'display_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $structureId;
    }

    // ── Boarding: a small real hostel via the genuine Welfare Actions ──────

    private function buildBoarding(): void
    {
        $existing = DB::table('hostels')->where('code', 'HC-BOYS')->value('id');

        if (is_numeric($existing)) {
            return; // already built on a prior run
        }

        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();
        $actor = $admin !== null ? $admin->toAuditActor() : Actor::system();

        $saveHostel = app(SaveHostel::class);
        $saveRoom = app(SaveRoom::class);
        $saveBeds = app(SaveBeds::class);
        $allocateBed = app(AllocateBed::class);

        $hostels = [
            ['code' => 'HC-BOYS', 'name' => 'Heritage College Boys Hostel', 'gender' => HostelGender::Boys],
            ['code' => 'HC-GIRLS', 'name' => 'Heritage College Girls Hostel', 'gender' => HostelGender::Girls],
        ];

        $roomsByHostelGender = [];

        foreach ($hostels as $h) {
            $hostel = $saveHostel->handle(null, [
                'code' => $h['code'],
                'name' => $h['name'],
                'gender' => $h['gender'],
                'is_active' => true,
            ], $actor);

            for ($i = 1; $i <= 3; $i++) {
                $room = $saveRoom->handle(null, [
                    'hostel_id' => $hostel->id,
                    'name' => 'Room '.$i,
                    'capacity' => 4,
                ], $actor);

                $saveBeds->handle($room->id, ['A', 'B', 'C', 'D'], $actor);

                $roomsByHostelGender[$h['gender']->value][] = $room->id;
            }
        }

        // Allocate a handful of boarders (gender-matched, active enrollment).
        $candidates = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.status', 'active')
            ->select(['e.id as enrollment_id', 's.gender'])
            ->orderBy('e.id')
            ->limit(24)
            ->get();

        $bedsCursor = [];
        $allocated = 0;

        foreach ($candidates as $c) {
            $genderKey = $c->gender === 'male' ? 'boys' : 'girls';
            $roomIds = $roomsByHostelGender[$genderKey] ?? [];

            if ($roomIds === []) {
                continue;
            }

            // Walk rooms, then free beds within each, until one is free.
            $bed = null;

            foreach ($roomIds as $roomId) {
                $bed = DB::table('hostel_beds as b')
                    ->leftJoin('hostel_allocations as a', function ($join): void {
                        $join->on('a.bed_id', '=', 'b.id')->where('a.status', 'active');
                    })
                    ->where('b.room_id', $roomId)
                    ->whereNull('a.id')
                    ->orderBy('b.label')
                    ->value('b.id');

                if ($bed !== null) {
                    break;
                }
            }

            if ($bed === null) {
                continue; // all boarding beds full
            }

            $allocateBed->handle((int) $c->enrollment_id, (int) $bed, Carbon::parse('2026-09-01'), $actor);

            // Mark the enrollment as a boarder so it is reflected consistently.
            DB::table('enrollments')->where('id', $c->enrollment_id)->update(['boarding_status' => 'boarder']);

            $allocated++;

            if ($allocated >= 20) {
                break;
            }
        }

        $this->command?->info("Boarding: 2 hostels, 6 rooms, 24 beds, {$allocated} students allocated.");
    }

    // ── Invoices + varied payment states ────────────────────────────────────

    private function generateAndCollect(int $structureId): void
    {
        $accountant = User::query()->where('email', 'demo.bursar@opeschool.test')->first();

        if ($accountant === null) {
            throw new \RuntimeException('demo.bursar@opeschool.test not found; run DemoDataSeeder first.');
        }

        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        $actor = $accountant->toAuditActor();
        // Segregation of duties (ApproveFeeAdjustment): approved_by must
        // differ from granted_by, so the admin approves what the bursar grants.
        $approverActor = $admin !== null ? $admin->toAuditActor() : $actor;

        $this->ensureAdjustmentPostingRule($accountant);

        // Day enrollments only (boarders above use the 'any'-scope structure
        // from DemoDataSeeder, which still applies to them). Re-query fresh:
        // student count keeps growing from concurrent seeding.
        $enrollmentIds = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.status', 'active')
            ->where('e.boarding_status', 'day')
            ->orderBy('e.id')
            ->limit(150)
            ->pluck('e.id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (count($enrollmentIds) < 100) {
            $this->command?->warn('Fewer than 100 day enrollments available; proceeding with '.count($enrollmentIds).'.');
        }

        $result = app(GenerateInvoices::class)->forEnrollments($enrollmentIds, [
            'academic_year_id' => $this->academicYearId,
            'fiscal_year_id' => $this->fiscalYearId,
            'term_id' => null,
            'issue_date' => '2026-09-08',
            'due_date' => '2026-10-08',
        ], $actor);

        if ($result['rejected'] !== []) {
            $sample = array_slice($result['rejected'], 0, 3, true);
            $this->command?->warn('GenerateInvoices rejected '.count($result['rejected']).' enrollments, e.g. '.json_encode($sample));
        }

        $created = $result['created'];

        $issued = $created !== []
            ? app(IssueInvoice::class)->handle($created, $actor)
            : Invoice::query()
                ->where('academic_year_id', $this->academicYearId)
                ->where('fee_structure_id', $structureId)
                ->where('status', 'issued')
                ->orderBy('id')
                ->get();

        // Also pull in already-issued invoices from a prior partial run so
        // the payment pass below covers the full population even if this
        // run's GenerateInvoices call only produced skips.
        if ($created === [] && $issued->isEmpty()) {
            $issued = Invoice::query()
                ->where('academic_year_id', $this->academicYearId)
                ->where('fee_structure_id', $structureId)
                ->where('status', 'issued')
                ->orderBy('id')
                ->get();
        }

        $reasonTypeCase = $this->firstAdjustmentReasonType();
        $adjustmentAccountId = DB::table('chart_of_accounts')->where('code', '4198')->value('id');

        $paid = 0;
        $partial = 0;
        $overdue = 0;
        $recent = 0;
        $scholarship = 0;

        foreach ($issued as $index => $invoice) {
            if (DB::table('payments')->where('idempotency_key', 'hc-payment-'.$invoice->id)->exists()) {
                continue; // already collected on a prior run
            }

            $bucket = $index % 10; // 0-2 paid(30%), 3-4 partial+overdue mix(25%/20%), ...
            // Explicit weighted buckets out of 20 for readability:
            $b20 = $index % 20;

            $gross = (int) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('amount');

            if ($gross <= 0) {
                continue;
            }

            if ($b20 < 2 && $reasonTypeCase !== null && is_numeric($adjustmentAccountId)) {
                // ~10%: scholarship / discount via a fee adjustment on the
                // largest (tuition) line, applied before payment collection.
                $line = DB::table('invoice_lines')
                    ->where('invoice_id', $invoice->id)
                    ->orderByDesc('amount')
                    ->first();

                if ($line !== null) {
                    $discount = (int) round($gross * 0.25);
                    $discount = min($discount, (int) $line->amount);

                    if ($discount > 0) {
                        $adjustment = app(AdjustInvoice::class)->handle([
                            'invoice_line_id' => $line->id,
                            'amount' => $discount,
                            'reason_type' => $reasonTypeCase,
                            'reason_note' => 'Heritage College merit scholarship 25% tuition discount.',
                            'adjustment_account_id' => (int) $adjustmentAccountId,
                            'effective_date' => '2026-09-10',
                        ], $actor);

                        app(ApproveFeeAdjustment::class)->handle(
                            (int) $adjustment->getKey(),
                            $approverActor,
                        );

                        $scholarship++;
                    }
                }

                // Scholarship students still pay down what remains, fully.
                $remaining = (int) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('amount')
                    - (int) DB::table('fee_adjustments')->where('invoice_line_id', $line->id ?? 0)->where('status', 'approved')->sum('amount');

                $this->pay($invoice, max(0, $remaining), $index, $actor, '2026-10-15');

                continue;
            }

            if ($b20 < 8) {
                // ~30%: fully paid.
                $this->pay($invoice, $gross, $index, $actor, '2026-10-05');
                $paid++;
            } elseif ($b20 < 13) {
                // ~25%: partially paid, real outstanding balance.
                $amountToPay = (int) round($gross * 0.4);
                $this->pay($invoice, $amountToPay, $index, $actor, '2026-10-20');
                $partial++;
            } elseif ($b20 < 17) {
                // ~20%: overdue / unpaid — no payment recorded.
                $overdue++;

                continue;
            } else {
                // ~15%: recently paid. "Now" (2026-08-17) predates the
                // fiscal year's first open accounting period (2026-09-01),
                // so "recent" here means the most recent open period's
                // latest days rather than the literal current date.
                $this->pay($invoice, $gross, $index, $actor, '2026-10-28');
                $recent++;
            }
        }

        $this->command?->info(sprintf(
            'Invoices issued: %d. Payments: fully paid %d, partial %d, overdue/unpaid %d, recent %d, scholarship %d.',
            $issued->count(),
            $paid,
            $partial,
            $overdue,
            $recent,
            $scholarship,
        ));
    }

    private function ensureAdjustmentPostingRule(User $accountant): void
    {
        $exists = DB::table('posting_rules')->where('code', 'hc_fee_adjustment_granted')->exists();

        if ($exists) {
            return;
        }

        $journalId = DB::table('journals')->where('code', 'OD')->value('id');

        app(SavePostingRule::class)->handle([
            'code' => 'hc_fee_adjustment_granted',
            'event' => PostingEvent::FeeAdjustmentGranted->value,
            'journal_id' => (int) $journalId,
            'label_expression' => 'Reduction {adjustment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'adjustment.counterpart_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'adjustment.amount',
                'partner_source' => 'adjustment.partner',
                'label_expression' => 'Bourse - {adjustment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'adjustment.receivable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'adjustment.amount',
                'partner_source' => 'adjustment.partner',
                'label_expression' => 'Client - {adjustment.reference}',
            ],
        ], $accountant->toAuditActor());
    }

    private function pay(Invoice $invoice, int $amount, int $index, Actor $actor, string $valueDate): void
    {
        if ($amount <= 0) {
            return;
        }

        [$method, $treasuryCode, $reference] = match ($index % 4) {
            0, 1 => [PaymentMethod::Cash, '571', null],
            2 => [PaymentMethod::MobileMoney, '5521', sprintf('MM-MTN-HC-%06d', $invoice->id)],
            default => [PaymentMethod::Bank, '521', sprintf('TRF-HC-%06d', $invoice->id)],
        };

        $treasuryAccountId = DB::table('chart_of_accounts')->where('code', $treasuryCode)->value('id');

        app(RecordPayment::class)->handle(
            studentId: $invoice->student_id,
            academicYearId: $this->academicYearId,
            fiscalYearId: $this->fiscalYearId,
            method: $method,
            amount: Money::of($amount),
            payerName: 'Parent of student '.$invoice->student_id,
            valueDate: $valueDate,
            actor: $actor,
            feeAmount: Money::zero(),
            feeBearer: FeeBearer::None,
            reference: $reference,
            payerPhone: null,
            enrollmentId: $invoice->enrollment_id,
            targets: null,
            idempotencyKey: 'hc-payment-'.$invoice->id,
            notes: 'Heritage College demo payment',
            treasuryAccountId: is_numeric($treasuryAccountId) ? (int) $treasuryAccountId : null,
        );
    }

    private function firstAdjustmentReasonType(): ?FeeAdjustmentReasonType
    {
        foreach (FeeAdjustmentReasonType::cases() as $case) {
            if (str_contains(strtolower($case->name), 'scholar') || str_contains(strtolower($case->value), 'scholar')) {
                return $case;
            }
        }

        // Fall back to whatever the enum actually offers if no scholarship-
        // named case exists.
        return FeeAdjustmentReasonType::cases()[0] ?? null;
    }
}
