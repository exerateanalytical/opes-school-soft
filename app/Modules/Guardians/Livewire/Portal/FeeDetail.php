<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The fee views that read the same statement and differ in presentation:
 *
 *   structure   mobile/fee-structure-breakdown.png
 *   outstanding mobile/outstanding-balance.png
 *   pay         mobile/make-payment.png + payment-method-selection.png
 *               + payment-processing.png
 *
 * One component rather than five, for the reason Academics gives: they share
 * the wide-grant check and the enrollment resolution, and five copies of those
 * is five chances for one to drift.
 *
 * The payment flow is real in the sense that matters - it authorizes on row 18
 * and then tells the truth. There is no gateway (spec §1 non-goals), so it
 * never pretends to take money. A screen that appeared to charge a parent and
 * silently did nothing would be the most damaging thing in this portal.
 */
#[Layout('layouts.portal')]
final class FeeDetail extends Component
{
    public int $studentId;

    public string $childName = '';

    public string $view = 'structure';

    /** The payment flow's step, so one screen covers all three designs. */
    public string $step = 'method';

    public string $method = 'mtn';

    public function mount(int $student, string $view = 'structure'): void
    {
        $policy = app(GuardianPortalPolicy::class);

        // Row 18 for the payment flow; the wide fee grant for the read views.
        if ($view === 'pay') {
            $policy->authorize(GuardianCapability::R18InitiatePayment, $student);
        } else {
            $policy->authorize(GuardianCapability::R16ViewOwnPayments, $student);
        }

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
        $this->view = in_array($view, ['structure', 'outstanding', 'pay'], true) ? $view : 'structure';
    }

    public function chooseMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * "Pay now" - which reaches the honest answer rather than a gateway.
     *
     * Deliberately does NOT call the API's 501 endpoint: this is the same
     * process, so there is nothing to learn from a round trip that returns a
     * message this screen already knows. The capability was checked on mount.
     */
    public function submitPayment(): void
    {
        $this->step = 'processing';
    }

    public function render(): mixed
    {
        $reader = app(ChildFeeStatement::class);
        $enrollmentId = $reader->latestEnrollmentId($this->studentId);

        $structure = collect();
        $totals = ['billed' => 0, 'paid' => 0, 'outstanding' => 0, 'next_due_on' => null];
        $statement = collect();
        $installments = collect();

        if ($enrollmentId !== null) {
            $structure = $reader->structure($enrollmentId);
            $totals = $reader->totals($enrollmentId);
            $statement = $reader->statement($enrollmentId);
            $installments = $reader->installments($enrollmentId);
        }

        return view('livewire.guardians.portal.fee-detail', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'view' => $this->view,
            'step' => $this->step,
            'method' => $this->method,
            'hasEnrollment' => $enrollmentId !== null,
            'structure' => $structure,
            'totals' => $totals,
            'statement' => $statement,
            'installments' => $installments,
            'currency' => $enrollmentId === null ? 'XAF' : $reader->currency($enrollmentId),
        ]);
    }
}
