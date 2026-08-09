{{-- docs/specs/10-documents.md §10.3 - Student Account Statement, LIVE. The
     "Generated on ... by ..." footer line (4.2) comes from documents.layout
     via document_footer.blade.php automatically for a live render - nothing
     here needs to print it again. --}}
@extends('documents.layout')

@php $s = $payload['statement']; @endphp

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 8pt 0;">{{ __('documents.statement.title', [], $document['language']) }}</h2>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.statement.student', [], $document['language']) }}:</strong> {{ $s['student_name'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.statement.matricule', [], $document['language']) }}:</strong> {{ $s['student_matricule'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.statement.class', [], $document['language']) }}:</strong> {{ $s['class_group'] }}</td>
            <td><strong>{{ __('documents.statement.as_of', [], $document['language']) }}:</strong> {{ $s['as_of'] }}</td>
        </tr>
    </table>

    <table class="doc-block">
        <thead>
            <tr style="border-bottom: 0.7pt solid #333;">
                <th style="text-align: left; padding: 3pt;">{{ __('documents.statement.date', [], $document['language']) }}</th>
                <th style="text-align: left; padding: 3pt;">{{ __('documents.statement.description', [], $document['language']) }}</th>
                <th style="text-align: left; padding: 3pt;">{{ __('documents.statement.reference', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.statement.debit', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.statement.credit', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ __('documents.statement.balance', [], $document['language']) }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($s['lines'] as $line)
                <tr>
                    <td style="padding: 3pt;">{{ $line['date'] }}</td>
                    <td style="padding: 3pt;">{{ $line['description'] }}</td>
                    <td style="padding: 3pt; font-family: monospace; font-size: 7.5pt;">{{ $line['reference'] }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ $line['debit'] > 0 ? \App\Support\Money\Money::of($line['debit'])->format(false) : '' }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ $line['credit'] > 0 ? \App\Support\Money\Money::of($line['credit'])->format(false) : '' }}</td>
                    <td style="padding: 3pt; text-align: right;">{{ \App\Support\Money\Money::of($line['balance'])->format(false) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding: 6pt; text-align: center;">{{ __('documents.statement.no_lines', [], $document['language']) }}</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top: 0.7pt solid #333;">
                <th colspan="5" style="text-align: left; padding: 3pt;">{{ __('documents.statement.closing_balance', [], $document['language']) }}</th>
                <th style="text-align: right; padding: 3pt;">{{ \App\Support\Money\Money::of($s['closing_balance'])->format() }}</th>
            </tr>
        </tfoot>
    </table>

    @include('documents.blocks.signature_block')
@endsection
