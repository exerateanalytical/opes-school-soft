{{-- docs/specs/03-tax-procurement.md §6.6 / 10-documents.md §15 (WHT-CERT).
     Printed content checklist per §6.6: school identity (school_header
     already carries NIU/RCCM), supplier identity, legal basis, period, base,
     rate, amount withheld, related document reference, issue date, signature
     + stamp - bilingual. --}}
@extends('documents.layout')

@php $a = $payload['attestation']; @endphp

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.attestation.title', [], $document['language']) }}</h2>

    <p class="doc-small"><strong>{{ __('documents.attestation.attestation_no', [], $document['language']) }}:</strong> {{ $a['attestation_no'] }}</p>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.attestation.supplier', [], $document['language']) }}:</strong> {{ $a['supplier_name'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.attestation.niu', [], $document['language']) }}:</strong> {{ $a['supplier_niu'] ?? '—' }}</td>
        </tr>
        @if (!empty($a['supplier_address']))
            <tr>
                <td colspan="2"><strong>{{ __('documents.attestation.address', [], $document['language']) }}:</strong> {{ $a['supplier_address'] }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>{{ __('documents.attestation.period', [], $document['language']) }}:</strong> {{ $a['period'] }}</td>
            <td><strong>{{ __('documents.attestation.related_document', [], $document['language']) }}:</strong> {{ $a['related_document'] ?: '—' }}</td>
        </tr>
    </table>

    <p class="doc-small">{{ __('documents.attestation.body', [], $document['language']) }}</p>

    <table class="doc-block">
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.attestation.legal_basis', [], $document['language']) }}</th>
            <td style="padding: 3pt;">{{ $a['legal_basis'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.attestation.base_amount', [], $document['language']) }}</th>
            <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($a['base_amount'])->format() }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt;">{{ __('documents.attestation.rate', [], $document['language']) }}</th>
            <td style="padding: 3pt; text-align: right;">{{ number_format($a['rate_bp'] / 100, 2) }}%</td>
        </tr>
        <tr style="border-top: 0.7pt solid #333;">
            <th style="text-align: left; padding: 3pt;">{{ __('documents.attestation.withheld_amount', [], $document['language']) }}</th>
            <td style="padding: 3pt; text-align: right; font-weight: bold;">{{ \App\Support\Money\Money::of($a['withheld_amount'])->format() }}</td>
        </tr>
    </table>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
