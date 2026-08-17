{{-- docs/specs/10-documents.md §12.4 - Gate Pass / Autorisation de sortie.
     Snapshot-backed (receipt pattern): $payload['pass'] was frozen at issue
     by the owning Action. No state header - this is an internal control
     slip, not a certificate under the school's letterhead authority. --}}
@extends('documents.layout')

@php
    $p = $payload['pass'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 8pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.gate_pass.title', [], $lang) }}
    </h2>

    <table class="doc-block doc-small" style="line-height: 2.2;">
        <tr>
            <td style="width: 40%;"><strong>{{ __('documents.gate_pass.student_name', [], $lang) }}:</strong></td>
            <td>{{ $p['student_name'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.gate_pass.class_group', [], $lang) }}:</strong></td>
            <td>{{ $p['class_group'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.gate_pass.reason', [], $lang) }}:</strong></td>
            <td>{{ $p['reason'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.gate_pass.date', [], $lang) }}:</strong></td>
            <td>{{ $p['date'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.gate_pass.time_out', [], $lang) }}:</strong></td>
            <td>{{ $p['time_out'] }}</td>
        </tr>
    </table>

    <p class="doc-muted doc-small">{{ __('documents.gate_pass.notification_notice', [], $lang) }}</p>

    @include('documents.blocks.signature_block')
@endsection
