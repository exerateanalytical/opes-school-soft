{{-- docs/specs/10-documents.md §10.1 - Fee Receipt (A5 default; the
     FEE-RECEIPT-POS template row renders the SAME view at 80mm width, and
     the table below fits both without a second file - dompdf simply wraps
     narrower). $payload['receipt'] is assembled by Fees\Actions\PrintReceipt;
     every string here comes from lang/documents.php (10-documents §4.6). --}}
@extends('documents.layout')

@php $r = $payload['receipt']; @endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.receipt.title', [], $document['language']) }}</h2>

    {{-- receipt_no is Fees' OWN number (04-fees §14), not the platform's
         series serial - series_code is deliberately NULL for this template
         (see the 310010 seed migration), so this is the only place it prints. --}}
    <p class="doc-small"><strong>{{ __('documents.receipt.receipt_no', [], $document['language']) }}:</strong> {{ $r['receipt_no'] }}</p>

    @if ($r['is_voided'])
        <p class="doc-center" style="color: #a00; font-weight: bold;">{{ __('documents.receipt.void_notice', [], $document['language']) }}</p>
    @endif

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.receipt.received_from', [], $document['language']) }}:</strong> {{ $r['received_from'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.receipt.date', [], $document['language']) }}:</strong> {{ $r['date'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.receipt.student', [], $document['language']) }}:</strong> {{ $r['student_name'] }}</td>
            <td><strong>{{ __('documents.receipt.matricule', [], $document['language']) }}:</strong> {{ $r['student_matricule'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.receipt.class', [], $document['language']) }}:</strong> {{ $r['class_group'] }}</td>
            <td><strong>{{ __('documents.receipt.method', [], $document['language']) }}:</strong> {{ __('documents.receipt.method_'.$r['method'], [], $document['language']) }}</td>
        </tr>
        @if (!empty($r['reference']))
            <tr>
                <td colspan="2"><strong>{{ __('documents.receipt.reference', [], $document['language']) }}:</strong> {{ $r['reference'] }}</td>
            </tr>
        @endif
    </table>

    <table class="doc-block">
        <thead>
            <tr style="border-bottom: 0.7pt solid #333;">
                <th style="text-align: left; padding: 3pt;">{{ __('documents.receipt.for', [], $document['language']) }}</th>
                <th style="text-align: left; padding: 3pt;">{{ __('documents.receipt.description', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.receipt.amount', [], $document['language']) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r['lines'] as $line)
                <tr>
                    <td style="padding: 3pt; font-family: monospace; font-size: 7.5pt;">{{ $line['invoice_no'] }}</td>
                    <td style="padding: 3pt;">{{ $line['description'] }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['amount'])->format(false) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="border-top: 0.7pt solid #333;">
                <th colspan="2" style="text-align: left; padding: 3pt;">{{ __('documents.receipt.total', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ \App\Support\Money\Money::of($r['amount'])->format() }}</th>
            </tr>
        </tfoot>
    </table>

    <p class="doc-small"><strong>{{ __('documents.receipt.amount_words', [], $document['language']) }}:</strong>
        {{ ucfirst($r['amount_words']) }}
        {{ __('documents.receipt.currency_suffix', [], $document['language']) }}</p>

    <p class="doc-small">{{ __('documents.receipt.paid_to_date', [], $document['language']) }}:
        {{ \App\Support\Money\Money::of($r['paid_to_date'])->format() }}</p>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
