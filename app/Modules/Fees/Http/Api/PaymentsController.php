<?php

declare(strict_types=1);

namespace App\Modules\Fees\Http\Api;

use App\Modules\Fees\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only v1 payments adapter (docs/plans/phase-12-13.md 12.4).
 *
 * Gated in routes/api.php by `can:fee.view` + `abilities:fee.view`. Amounts
 * are integer XAF exactly as stored; the receipt number is presented because
 * it is the payment's public identity (04-fees 6.1), but nothing here can
 * mint one - this adapter is read-only by construction (GET routes only).
 */
final class PaymentsController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $payments = Payment::query()
            ->when($request->filled('student_id'), function ($query) use ($request): void {
                $query->where('student_id', $request->integer('student_id'));
            })
            ->when($request->filled('academic_year_id'), function ($query) use ($request): void {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            })
            ->when($request->filled('payment_method'), function ($query) use ($request): void {
                $query->where('payment_method', $request->string('payment_method')->toString());
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Payment $payment): array => $this->present($payment),
                $payments->items(),
            ),
            'meta' => [
                'page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json(['data' => $this->present($payment)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'receipt_no' => $payment->receipt_no,
            'student_id' => $payment->student_id,
            'enrollment_id' => $payment->enrollment_id,
            'academic_year_id' => $payment->academic_year_id,
            'fiscal_year_id' => $payment->fiscal_year_id,
            'payment_method' => $payment->payment_method->value,
            'amount' => $payment->amount,
            'fee_amount' => $payment->fee_amount,
            'fee_bearer' => $payment->fee_bearer->value,
            'reference' => $payment->reference,
            'payer_name' => $payment->payer_name,
            'value_date' => $payment->value_date,
            'posting_date' => $payment->posting_date,
            'clearing_state' => $payment->clearing_state->value,
            'unallocated_amount' => $payment->unallocated_amount,
        ];
    }
}
