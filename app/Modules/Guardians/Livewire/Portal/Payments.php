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

/**
 * `/portal/payments` - 07-students.md 7.5 rows 16 and 17, across every child.
 *
 * Deliberately NOT child-scoped, and that is the interesting part: row 16
 * ("payments made by THIS guardian") is granted on any valid link without
 * naming a child, so a parent who receives no invoices at all is still
 * entitled to a record of their own money. Scoping this page to one child
 * would silently withhold that.
 *
 * The width of what each child contributes is decided per child:
 *   the WIDE grant (rows 15/17) - every receipt against that child;
 *   the row-16 FLOOR - only the rows that match this guardian's own number.
 *
 * `is_own` is a best-effort phone match because `payments` still has no
 * `payer_guardian_id` column; it is a DISPLAY label, and authorization always
 * takes the safe side of that approximation. ChildFeeStatement's docblock is
 * the long version.
 */
#[Layout('layouts.portal')]
final class Payments extends Component
{
    public function render(): mixed
    {
        $context = PortalContext::current();
        $policy = app(GuardianPortalPolicy::class);
        $reader = app(ChildFeeStatement::class);

        $rows = [];

        if ($context !== null) {
            $links = $context->validLinks();
            $studentIds = array_map(static fn ($link): int => (int) $link->student_id, $links);

            $names = $studentIds === [] ? collect() : DB::table('students')
                ->whereIn('id', $studentIds)
                ->get(['id', 'first_name', 'last_name'])
                ->keyBy('id');

            foreach ($studentIds as $studentId) {
                $canWide = $policy->allows(GuardianCapability::R15ViewReceipts, $studentId)
                    || $policy->allows(GuardianCapability::R17ViewOtherGuardianPayments, $studentId);
                $canOwn = $policy->allows(GuardianCapability::R16ViewOwnPayments, $studentId);

                if (! $canWide && ! $canOwn) {
                    continue;
                }

                $enrollmentId = $reader->latestEnrollmentId($studentId);

                if ($enrollmentId === null) {
                    continue;
                }

                $student = $names->get($studentId);
                $childName = $student === null
                    ? '—'
                    : trim($student->first_name.' '.$student->last_name);
                $currency = $reader->currency($enrollmentId);

                foreach ($reader->receipts($enrollmentId, $context->guardian->phone) as $receipt) {
                    if (! $canWide && ! $receipt->is_own) {
                        continue;
                    }

                    $rows[] = [
                        'id' => $receipt->id,
                        'student_id' => $studentId,
                        'child_name' => $childName,
                        'receipt_no' => $receipt->receipt_no,
                        'paid_on' => $receipt->value_date,
                        'amount' => $receipt->amount,
                        'currency' => $currency,
                        'payment_method' => $receipt->payment_method,
                        'clearing_state' => $receipt->clearing_state,
                        'is_own' => $receipt->is_own,
                    ];
                }
            }

            usort(
                $rows,
                static fn (array $a, array $b): int => [$b['paid_on'], $b['id']] <=> [$a['paid_on'], $a['id']],
            );
        }

        return view('livewire.guardians.portal.payments', ['payments' => $rows]);
    }
}
