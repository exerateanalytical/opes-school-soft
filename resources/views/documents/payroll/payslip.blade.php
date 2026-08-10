{{-- docs/specs/10-documents.md §11.1 and 05-hr-payroll.md §14.1 - the payslip.

     Every figure is read from the payload Payroll\Actions\PrintPayslip
     assembled out of the APPROVED run's `payroll_items` / `payroll_lines`
     rows. Nothing is recomputed here: the gross, the bases, the IRPP and the
     net are the columns the payroll engine wrote and posted to the ledger, so
     a payslip that disagreed with the journal would be a defect, not a
     rounding difference.

     Employer charges are printed in their OWN block, explicitly labelled as
     not deducted from pay - a Cameroonian payslip shows them, and showing
     them inside the deductions block would understate the net. --}}
@extends('documents.layout')

@php
    $lang = $document['language'];
    $slip = $payload['payslip'];
    $employer = is_array($slip['employer'] ?? null) ? $slip['employer'] : [];
    $employee = is_array($slip['employee'] ?? null) ? $slip['employee'] : [];
    $money = static fn (int|string|null $v): string => $v === null
        ? '—'
        : \App\Support\Money\Money::of((int) $v)->format(false);
@endphp

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.payslip.title', [], $lang) }}</h2>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 8pt;">
                <strong>{{ __('documents.payslip.employer', [], $lang) }}</strong><br>
                {{ $employer['name'] ?? '' }}<br>
                @if (($employer['cnps_employer_number'] ?? null) !== null)
                    {{ __('documents.payslip.cnps_employer_number', [], $lang) }}: {{ $employer['cnps_employer_number'] }}<br>
                @endif
                @if (($employer['dipe_number'] ?? null) !== null)
                    {{ __('documents.payslip.dipe', [], $lang) }}: {{ $employer['dipe_number'] }}<br>
                @endif
                @if (($employer['niu'] ?? null) !== null)
                    {{ __('documents.payslip.niu', [], $lang) }}: {{ $employer['niu'] }}
                @endif
            </td>
            <td style="width: 50%; vertical-align: top;">
                <strong>{{ __('documents.payslip.employee', [], $lang) }}</strong><br>
                {{ $employee['name'] ?? $subject['label'] }}<br>
                {{ __('documents.payslip.staff_no', [], $lang) }}: {{ $employee['staff_no'] ?? '—' }}<br>
                @if (($employee['position'] ?? null) !== null)
                    {{ __('documents.payslip.position', [], $lang) }}: {{ $employee['position'] }}<br>
                @endif
                @if (($employee['contract_type'] ?? null) !== null)
                    {{ __('documents.payslip.contract_type', [], $lang) }}: {{ $employee['contract_type'] }}<br>
                @endif
                {{-- The staff member's OWN CNPS number is encrypted at rest and
                     is deliberately not decrypted for this print; the employer
                     number above is what a CNPS inspector reconciles against. --}}
                {{ __('documents.payslip.period', [], $lang) }}: <strong>{{ $slip['period'] }}</strong><br>
                @if (($slip['days_worked'] ?? null) !== null)
                    {{ __('documents.payslip.days_worked', [], $lang) }}: {{ $slip['days_worked'] }} / {{ $slip['days_in_period'] ?? '' }}
                @endif
            </td>
        </tr>
    </table>

    @php
        $blocks = [
            'earnings' => $slip['earnings'],
            'employee_deductions' => $slip['employee_deductions'],
            'employer_charges' => $slip['employer_charges'],
            'informational' => $slip['informational'],
        ];
    @endphp

    @foreach ($blocks as $key => $lines)
        @if ($lines !== [])
            <p class="doc-small" style="margin: 6pt 0 2pt 0; font-weight: bold;">
                {{ __('documents.payslip.'.$key, [], $lang) }}
            </p>
            <table class="doc-block" style="font-size: 8pt;">
                <thead>
                    <tr style="border-bottom: 0.7pt solid #333;">
                        <th style="text-align: left; padding: 2pt;">{{ __('documents.payslip.component', [], $lang) }}</th>
                        <th style="text-align: right; padding: 2pt; width: 70pt;">{{ __('documents.payslip.base', [], $lang) }}</th>
                        <th style="text-align: right; padding: 2pt; width: 50pt;">{{ __('documents.payslip.rate', [], $lang) }}</th>
                        <th style="text-align: right; padding: 2pt; width: 80pt;">{{ __('documents.payslip.amount', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr style="border-bottom: 0.3pt solid #bbb;">
                            <td style="padding: 2pt;">{{ $line['name'] }}</td>
                            <td style="padding: 2pt; text-align: right;">{{ $money($line['base_amount']) }}</td>
                            <td style="padding: 2pt; text-align: right;">{{ $line['rate'] ?? '' }}</td>
                            <td style="padding: 2pt; text-align: right;">{{ $money($line['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($slip['earnings'] === [] && $slip['employee_deductions'] === [])
        <p class="doc-small">{{ __('documents.payslip.no_lines', [], $lang) }}</p>
    @endif

    {{-- Gross -> deductions -> NET, in that order and nothing between them. --}}
    <table class="doc-block doc-small">
        <tr>
            <th style="text-align: left; padding: 2pt;">{{ __('documents.payslip.gross', [], $lang) }}</th>
            <td style="text-align: right; padding: 2pt;">{{ $money($slip['gross']) }}</td>
        </tr>
        <tr>
            <td style="padding: 2pt;">{{ __('documents.payslip.sbt', [], $lang) }}</td>
            <td style="text-align: right; padding: 2pt;">{{ $money($slip['sbt']) }}</td>
        </tr>
        <tr>
            <td style="padding: 2pt;">{{ __('documents.payslip.taxable_base', [], $lang) }}</td>
            <td style="text-align: right; padding: 2pt;">{{ $money($slip['taxable_base']) }}</td>
        </tr>
        <tr>
            <td style="padding: 2pt;">{{ __('documents.payslip.irpp', [], $lang) }}</td>
            <td style="text-align: right; padding: 2pt;">{{ $money($slip['irpp']) }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 2pt;">{{ __('documents.payslip.total_deductions', [], $lang) }}</th>
            <td style="text-align: right; padding: 2pt;">{{ $money($slip['total_deductions']) }}</td>
        </tr>
        <tr style="border-top: 0.7pt solid #333;">
            <th style="text-align: left; padding: 3pt; font-size: 11pt;">{{ __('documents.payslip.net', [], $lang) }}</th>
            <td style="text-align: right; padding: 3pt; font-size: 11pt; font-weight: bold;">{{ $money($slip['net']) }}</td>
        </tr>
    </table>

    <p class="doc-small"><strong>{{ __('documents.payslip.amount_words', [], $lang) }}:</strong>
        {{ ucfirst($slip['net_words']) }}
        {{ __('documents.payslip.currency_suffix', [], $lang) }}</p>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;">
                <strong>{{ __('documents.payslip.payment_method', [], $lang) }}:</strong>
                {{ $slip['payment_method'] ?? __('documents.payslip.not_paid_yet', [], $lang) }}
            </td>
            <td style="width: 50%;">
                <strong>{{ __('documents.payslip.payment_date', [], $lang) }}:</strong>
                {{ $slip['payment_date'] ?? __('documents.payslip.not_paid_yet', [], $lang) }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>{{ __('documents.payslip.total_employer_charges', [], $lang) }}:</strong>
                {{ $money($slip['total_employer_charges']) }}
            </td>
            <td>
                <strong>{{ __('documents.payslip.ytd_sbt', [], $lang) }}:</strong> {{ $money($slip['ytd_sbt']) }}
                &nbsp;·&nbsp;
                <strong>{{ __('documents.payslip.ytd_irpp', [], $lang) }}:</strong> {{ $money($slip['ytd_irpp']) }}
            </td>
        </tr>
    </table>

    <p class="doc-center doc-muted">{{ __('documents.payslip.keep_notice', [], $lang) }}</p>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
