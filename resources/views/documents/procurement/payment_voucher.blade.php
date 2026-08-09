{{-- phase-12-13 D3 - Payment Voucher for an already-recorded SupplierPayment
     (03-tax-procurement §9's "print advice"). --}}
@extends('documents.layout')

@php $v = $payload['voucher']; @endphp

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.voucher.title', [], $document['language']) }}</h2>

    <p class="doc-small"><strong>{{ __('documents.voucher.voucher_no', [], $document['language']) }}:</strong> {{ $v['payment_no'] }}</p>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.voucher.paid_to', [], $document['language']) }}:</strong> {{ $v['supplier_name'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.voucher.date', [], $document['language']) }}:</strong> {{ $v['date'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.voucher.niu', [], $document['language']) }}:</strong> {{ $v['supplier_niu'] ?? '—' }}</td>
            <td><strong>{{ __('documents.voucher.method', [], $document['language']) }}:</strong> {{ $v['treasury_account'] }} ({{ $v['method'] }})</td>
        </tr>
        @if (!empty($v['reference']))
            <tr>
                <td colspan="2"><strong>{{ __('documents.receipt.reference', [], $document['language']) }}:</strong> {{ $v['reference'] }}</td>
            </tr>
        @endif
    </table>

    @if ($v['allocations'] !== [])
        <table class="doc-block">
            <thead>
                <tr style="border-bottom: 0.7pt solid #333;">
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.voucher.invoice', [], $document['language']) }}</th>
                    <th style="text-align: right; padding: 3pt;">{{ __('documents.voucher.amount', [], $document['language']) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($v['allocations'] as $line)
                    <tr>
                        <td style="padding: 3pt; font-family: monospace; font-size: 7.5pt;">{{ $line['invoice_no'] }}</td>
                        <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['amount'])->format(false) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="doc-block">
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.voucher.gross_amount', [], $document['language']) }}</th>
            <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($v['gross_amount'])->format() }}</td>
        </tr>
        @if ($v['withholding_amount'] > 0)
            <tr>
                <th style="text-align: left; padding: 3pt;">{{ __('documents.voucher.withholding_amount', [], $document['language']) }}</th>
                <td style="padding: 3pt; text-align: right;">-{{ \App\Support\Money\Money::of($v['withholding_amount'])->format() }}</td>
            </tr>
        @endif
        @if ($v['fee_amount'] > 0)
            <tr>
                <th style="text-align: left; padding: 3pt;">{{ __('documents.voucher.fee_amount', [], $document['language']) }}</th>
                <td style="padding: 3pt; text-align: right;">-{{ \App\Support\Money\Money::of($v['fee_amount'])->format() }}</td>
            </tr>
        @endif
        <tr style="border-top: 0.7pt solid #333;">
            <th style="text-align: left; padding: 3pt;">{{ __('documents.voucher.net_amount', [], $document['language']) }}</th>
            <td style="padding: 3pt; text-align: right; font-weight: bold;">{{ \App\Support\Money\Money::of($v['net_amount'])->format() }}</td>
        </tr>
    </table>

    <p class="doc-small"><strong>{{ __('documents.voucher.amount_words', [], $document['language']) }}:</strong>
        {{ ucfirst($v['amount_words']) }}
        {{ __('documents.voucher.currency_suffix', [], $document['language']) }}</p>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
