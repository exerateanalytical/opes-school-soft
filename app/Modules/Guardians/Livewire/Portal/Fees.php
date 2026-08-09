<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/fees` - 07-students.md 7.5 rows 13-17.
 *
 * `receives_invoices OR is_fee_payer` (rows 13, 14, 15, 17) is the wide
 * grant: invoices, the running statement and every receipt against the
 * child's current enrollment. Row 16 - payments made by THIS guardian - is
 * the floor every valid link carries regardless; see ChildFeeStatement's
 * docblock for why that narrowing is best-effort (phone match) rather than
 * exact (no `payer_guardian_id` column exists yet).
 *
 * Read-only. Row 18 (initiate payment) is out of this build's scope.
 */
#[Layout('layouts.portal')]
final class Fees extends Component
{
    public int $studentId;

    public string $childName = '';

    public bool $canWide = false;

    public string $activeTab = 'statement';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R16ViewOwnPayments, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);

        $this->canWide = app(GuardianPortalPolicy::class)->allows(GuardianCapability::R13ViewInvoices, $student)
            || app(GuardianPortalPolicy::class)->allows(GuardianCapability::R14ViewFeeStatement, $student)
            || app(GuardianPortalPolicy::class)->allows(GuardianCapability::R17ViewOtherGuardianPayments, $student);

        if (! $this->canWide) {
            $this->activeTab = 'receipts';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['statement', 'invoices', 'receipts'], true) ? $tab : 'statement';
    }

    private function latestEnrollmentId(): ?int
    {
        $id = DB::table('enrollments')
            ->where('student_id', $this->studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function render(): mixed
    {
        $context = PortalContext::current();
        $enrollmentId = $this->latestEnrollmentId();
        $reader = app(ChildFeeStatement::class);

        $statement = [];
        $closing = 0;
        $invoices = collect();
        $receipts = collect();

        if ($enrollmentId !== null) {
            if ($this->canWide) {
                foreach ($reader->statement($enrollmentId) as $row) {
                    $statement[] = [
                        'date' => $row->date,
                        'description' => $row->description,
                        'reference' => $row->reference,
                        'debit' => $row->debit,
                        'credit' => $row->credit,
                        'balance' => $row->balance,
                    ];
                    $closing = $row->balance;
                }

                $invoices = $reader->invoices($enrollmentId);
            }

            $allReceipts = $context === null ? collect() : $reader->receipts($enrollmentId, $context->guardian->phone);
            $receipts = $this->canWide ? $allReceipts : $allReceipts->filter(static fn (\stdClass $r): bool => $r->is_own)->values();
        }

        return view('livewire.guardians.portal.fees', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'statement' => $statement,
            'closingBalance' => $closing,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'hasEnrollment' => $enrollmentId !== null,
        ]);
    }
}
