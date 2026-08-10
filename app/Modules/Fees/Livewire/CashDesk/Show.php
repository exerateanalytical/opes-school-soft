<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire\CashDesk;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/04-fees.md §11.7 - one cash-desk session's close-out sheet.
 *
 * The document a bursar signs and files: who was on the till, on which box,
 * from when to when, the declared opening float, every collection the shift
 * took, the computed expected balance, the counted balance, and - when they
 * disagree - the variance, its mandatory reason and the journal entry that
 * carried it.
 *
 * Read-only, exactly like SupplierInvoices\Show: open and close are acts of
 * the Cashier screen, where the person holding the money is standing. This
 * screen only reports. Gated `fee.view`, the same permission that opens the
 * cashier screen itself.
 *
 * Every figure is recomputed from `payments` at render, never read from a
 * running total - the whole point of a close-out sheet is that it is derived
 * from the collections themselves.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $sessionId;

    public function mount(int $session): void
    {
        Gate::authorize(Permission::FeeView->value);

        $this->sessionId = $session;

        if (! DB::table('cash_desk_sessions')->where('id', $session)->exists()) {
            abort(404);
        }
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::FeeView->value);

        $session = $this->session();
        $rows = [];

        foreach ($this->collections() as $line) {
            $rows[] = [
                $line['receipt_no'],
                $line['time'],
                $line['student'],
                $line['payer'],
                Money::of($line['amount'])->format(false),
            ];
        }

        // The close-out figures ride as trailing rows of the same table -
        // PdfExport's shared shell (reports/pdf-shell) takes a headers/rows
        // pair and nothing else, and inventing a second shell for one
        // document would break the house look every other document shares.
        $rows[] = ['', '', '', 'Opening float', Money::of($session['opening_float'])->format(false)];
        $rows[] = ['', '', '', 'Collections', Money::of($session['collected'])->format(false)];
        $rows[] = ['', '', '', 'Expected', Money::of($session['expected'])->format(false)];

        if ($session['status'] !== 'open') {
            $rows[] = ['', '', '', 'Counted', Money::of((int) $session['counted_cash'])->format(false)];
            $rows[] = ['', '', '', 'Variance', Money::of((int) $session['variance'])->format(false)];
            $rows[] = ['', '', '', 'Reason', (string) ($session['variance_reason'] ?? '—')];
        }

        return PdfExport::download(
            title: 'Cash Desk Close-out '.$session['session_no'],
            headers: ['Receipt', 'Time', 'Student', 'Payer', 'Amount'],
            rows: $rows,
            filename: 'cash-desk-'.str_replace('/', '-', $session['session_no']).'.pdf',
        );
    }

    /**
     * @return array{id: int, session_no: string, status: string, business_date: string, treasury_label: string, opened_by_name: string, opened_at: string, closed_by_name: string|null, closed_at: string|null, opening_float: int, collected: int, collections: int, expected: int, expected_cash: int|null, counted_cash: int|null, variance: int|null, variance_reason: string|null, journal_entry_id: int|null, piece_no: string|null}
     */
    private function session(): array
    {
        /** @var object{id: int|string, session_no: string, status: string, business_date: string, opened_at: string, closed_at: string|null, opening_float: int|string, expected_cash: int|string|null, counted_cash: int|string|null, variance: int|string|null, variance_reason: string|null, journal_entry_id: int|string|null, account_code: string, account_name: string, opened_by_name: string, closed_by_name: string|null, piece_no: string|null} $row */
        $row = DB::table('cash_desk_sessions as s')
            ->join('chart_of_accounts as a', 'a.id', '=', 's.treasury_account_id')
            ->join('users as opener', 'opener.id', '=', 's.opened_by')
            ->leftJoin('users as closer', 'closer.id', '=', 's.closed_by')
            ->leftJoin('journal_entries as je', 'je.id', '=', 's.journal_entry_id')
            ->where('s.id', $this->sessionId)
            ->firstOrFail([
                's.id', 's.session_no', 's.status', 's.business_date',
                's.opened_at', 's.closed_at', 's.opening_float',
                's.expected_cash', 's.counted_cash', 's.variance', 's.variance_reason',
                's.journal_entry_id',
                'a.code as account_code', 'a.name as account_name',
                'opener.name as opened_by_name', 'closer.name as closed_by_name',
                'je.piece_no',
            ]);

        $collected = 0;
        $count = 0;

        foreach ($this->collections() as $line) {
            $collected += $line['amount'];
            $count++;
        }

        $openingFloat = (int) $row->opening_float;

        return [
            'id' => (int) $row->id,
            'session_no' => (string) $row->session_no,
            'status' => (string) $row->status,
            'business_date' => (string) $row->business_date,
            'treasury_label' => $row->account_code.' · '.$row->account_name,
            'opened_by_name' => (string) $row->opened_by_name,
            'opened_at' => (string) $row->opened_at,
            'closed_by_name' => $row->closed_by_name === null ? null : (string) $row->closed_by_name,
            'closed_at' => $row->closed_at === null ? null : (string) $row->closed_at,
            'opening_float' => $openingFloat,
            'collected' => $collected,
            'collections' => $count,
            'expected' => $openingFloat + $collected,
            'expected_cash' => $row->expected_cash === null ? null : (int) $row->expected_cash,
            'counted_cash' => $row->counted_cash === null ? null : (int) $row->counted_cash,
            'variance' => $row->variance === null ? null : (int) $row->variance,
            'variance_reason' => $row->variance_reason === null ? null : (string) $row->variance_reason,
            'journal_entry_id' => $row->journal_entry_id === null ? null : (int) $row->journal_entry_id,
            'piece_no' => $row->piece_no === null ? null : (string) $row->piece_no,
        ];
    }

    /**
     * The shift's live collections - voided and bounced receipts excluded,
     * exactly as CloseCashDeskSession computes `expected_cash`, so the sheet
     * and the ledger can never tell two different stories.
     *
     * @return list<array{receipt_no: string, time: string, student: string, payer: string, amount: int, method: string}>
     */
    private function collections(): array
    {
        $rows = DB::table('payments as p')
            ->leftJoin('students as st', 'st.id', '=', 'p.student_id')
            ->where('p.cash_desk_session_id', $this->sessionId)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->orderBy('p.id')
            ->get([
                'p.receipt_no', 'p.created_at', 'p.amount', 'p.payer_name', 'p.payment_method',
                'st.first_name', 'st.last_name',
            ]);

        $lines = [];

        foreach ($rows as $row) {
            /** @var object{receipt_no: string, created_at: string|null, amount: int|string, payer_name: string, payment_method: string, first_name: string|null, last_name: string|null} $row */
            $lines[] = [
                'receipt_no' => (string) $row->receipt_no,
                'time' => $row->created_at === null ? '' : substr((string) $row->created_at, 11, 5),
                'student' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
                'payer' => (string) $row->payer_name,
                'amount' => (int) $row->amount,
                'method' => (string) $row->payment_method,
            ];
        }

        return $lines;
    }

    public function render(): mixed
    {
        return view('livewire.fees.cash-desk.show', [
            'session' => $this->session(),
            'collections' => $this->collections(),
        ]);
    }
}
