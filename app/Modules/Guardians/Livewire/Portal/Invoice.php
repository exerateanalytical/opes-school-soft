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
 * `/portal/children/{student}/invoices/{invoice}` - 07-students.md 7.5 row 13.
 *
 * The web counterpart of `GET /v1/me/children/{s}/invoices/{id}`, reading
 * through the same `ChildFeeStatement::invoice()`.
 *
 * The invoice is resolved THROUGH the child's enrollment, never on its own, so
 * an id belonging to another family is simply not found - 404, not 403. A 403
 * would confirm the invoice exists somewhere, which is the disclosure row 32
 * exists to prevent.
 */
#[Layout('layouts.portal')]
final class Invoice extends Component
{
    public int $studentId;

    public int $invoiceId;

    public string $childName = '';

    public function mount(int $student, int $invoice): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R13ViewInvoices, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->invoiceId = $invoice;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $reader = app(ChildFeeStatement::class);
        $enrollmentId = $reader->latestEnrollmentId($this->studentId);
        $invoice = $enrollmentId === null ? null : $reader->invoice($enrollmentId, $this->invoiceId);

        if ($invoice === null) {
            throw new NotFoundHttpException();
        }

        return view('livewire.guardians.portal.invoice', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'invoice' => $invoice,
        ]);
    }
}
