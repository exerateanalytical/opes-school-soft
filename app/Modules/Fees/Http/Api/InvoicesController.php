<?php

declare(strict_types=1);

namespace App\Modules\Fees\Http\Api;

use App\Modules\Fees\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only v1 invoices adapter (docs/plans/phase-12-13.md 12.4).
 *
 * Gated in routes/api.php by `can:fee.view` + `abilities:fee.view` - the
 * same permission the /finance screens require, per 00-core 6.1's rule that
 * every adapter enforces the same gates.
 *
 * Amounts are integer XAF minor units exactly as stored (04-fees A1: totals
 * are derived, never stored - grossTotal() sums the lines here too).
 */
final class InvoicesController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $invoices = Invoice::query()
            ->when($request->filled('student_id'), function ($query) use ($request): void {
                $query->where('student_id', $request->integer('student_id'));
            })
            ->when($request->filled('academic_year_id'), function ($query) use ($request): void {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Invoice $invoice): array => $this->present($invoice),
                $invoices->items(),
            ),
            'meta' => [
                'page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => $this->present($invoice)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'student_id' => $invoice->student_id,
            'enrollment_id' => $invoice->enrollment_id,
            'academic_year_id' => $invoice->academic_year_id,
            'fiscal_year_id' => $invoice->fiscal_year_id,
            'term_id' => $invoice->term_id,
            'type' => $invoice->type->value,
            'status' => $invoice->status->value,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'currency' => $invoice->currency,
            'gross_total' => $invoice->grossTotal(),
        ];
    }
}
