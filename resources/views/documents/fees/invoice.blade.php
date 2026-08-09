{{-- docs/specs/10-documents.md §10.2 - Fee Invoice. Agent-collected lines
     render in their OWN subtotalled block, labelled per the spec, because
     the school is not the principal for those francs (04-fees §C5). --}}
@extends('documents.layout')

@php $inv = $payload['invoice']; @endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.invoice.title', [], $document['language']) }}</h2>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.invoice.to', [], $document['language']) }}:</strong> {{ $inv['student_name'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.invoice.date', [], $document['language']) }}:</strong> {{ $inv['date'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.invoice.matricule', [], $document['language']) }}:</strong> {{ $inv['student_matricule'] }}</td>
            <td><strong>{{ __('documents.invoice.due_date', [], $document['language']) }}:</strong> {{ $inv['due_date'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.invoice.class', [], $document['language']) }}:</strong> {{ $inv['class_group'] }}</td>
            <td></td>
        </tr>
    </table>

    <table class="doc-block">
        <thead>
            <tr style="border-bottom: 0.7pt solid #333;">
                <th style="text-align: left; padding: 3pt;">{{ __('documents.invoice.description', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.invoice.amount', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.invoice.tax', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.invoice.total', [], $document['language']) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($inv['own_lines'] as $line)
                <tr>
                    <td style="padding: 3pt;">{{ $line['description'] }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['amount'])->format(false) }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['tax'])->format(false) }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['total'])->format(false) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="border-top: 0.7pt solid #333;">
                <th colspan="3" style="text-align: left; padding: 3pt;">{{ __('documents.invoice.total', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ \App\Support\Money\Money::of($inv['own_total'])->format(false) }}</th>
            </tr>
        </tfoot>
    </table>

    @if ($inv['third_party_lines'] !== [])
        <p class="doc-small" style="font-weight: bold;">{{ __('documents.invoice.third_party', [], $document['language']) }}</p>
        <table class="doc-block">
            <tbody>
                @foreach ($inv['third_party_lines'] as $line)
                    <tr>
                        <td style="padding: 3pt;">{{ $line['description'] }}</td>
                        <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['total'])->format(false) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top: 0.7pt solid #333;">
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.invoice.total', [], $document['language']) }}</th>
                    <th style="text-align: right; padding: 3pt;">{{ \App\Support\Money\Money::of($inv['third_party_total'])->format(false) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif

    <table class="doc-block">
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.invoice.total_due', [], $document['language']) }}</th>
            <td style="text-align: right; padding: 3pt; font-weight: bold;">{{ \App\Support\Money\Money::of($inv['grand_total'])->format() }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.invoice.balance_due', [], $document['language']) }}</th>
            <td style="text-align: right; padding: 3pt; font-weight: bold;">{{ \App\Support\Money\Money::of($inv['balance_due'])->format() }}</td>
        </tr>
    </table>

    <p class="doc-small"><strong>{{ __('documents.invoice.amount_words', [], $document['language']) }}:</strong>
        {{ ucfirst($inv['amount_words']) }}
        {{ $document['language'] === 'fr' ? 'francs CFA.' : 'CFA francs.' }}</p>

    <p class="doc-center doc-small">{{ __('documents.invoice.thank_you', [], $document['language']) }}</p>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
