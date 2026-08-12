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
 * `/portal/children/{student}/receipts/{payment}` - 07-students.md 7.5 row 15.
 *
 * A receipt DESCRIPTOR, not a PDF, exactly as the API's counterpart returns:
 * 10-documents §4.8 makes RenderDocument the only path to a signed document
 * and gates it on `documents.print`, a staff permission the guardian role must
 * never hold. Forking that Action would put a second, weaker path to a signed
 * FINANCIAL document into the product.
 *
 * What a parent actually needs at the counter - the number, the amount, the
 * date, the method - is all here, with the receipt number rendered as the code
 * the front desk checks.
 */
#[Layout('layouts.portal')]
final class Receipt extends Component
{
    public int $studentId;

    public int $paymentId;

    public string $childName = '';

    public function mount(int $student, int $payment): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R15ViewReceipts, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->paymentId = $payment;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $reader = app(ChildFeeStatement::class);
        $enrollmentId = $reader->latestEnrollmentId($this->studentId);
        $receipt = $enrollmentId === null ? null : $reader->receipt($enrollmentId, $this->paymentId);

        if ($receipt === null) {
            throw new NotFoundHttpException();
        }

        return view('livewire.guardians.portal.receipt', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'receipt' => $receipt,
            'currency' => $reader->currency($enrollmentId),
        ]);
    }
}
