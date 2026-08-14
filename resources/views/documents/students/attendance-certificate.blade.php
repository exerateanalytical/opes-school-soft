{{-- docs/specs/10-documents.md §7.11 - Attestation of Attendance /
     Attestation de présence. Rate over REGISTERS ACTUALLY TAKEN (07-students
     C5); the Action refuses an empty denominator before this view ever
     renders. Payload frozen at issue by PrintAttendanceCertificate. --}}
@extends('documents.layout')

@php
    $c = $payload['certificate'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 10pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.attend_cert.title', [], $lang) }}
    </h2>

    @include('documents.blocks.subject_identity', ['identity' => $c['identity']])

    <p style="margin: 12pt 0; line-height: 1.7; text-align: justify;">
        {{ __('documents.attend_cert.body', ['from' => $c['from'], 'to' => $c['to']], $lang) }}
    </p>

    <table class="doc-block doc-small" style="width: 70%; margin: 0 auto;">
        <tr>
            <td style="padding: 3pt;"><strong>{{ __('documents.attend_cert.registers', [], $lang) }}</strong></td>
            <td style="padding: 3pt; text-align: right;">{{ $c['registers'] }}</td>
        </tr>
        <tr>
            <td style="padding: 3pt;"><strong>{{ __('documents.attend_cert.present', [], $lang) }}</strong></td>
            <td style="padding: 3pt; text-align: right;">{{ $c['present'] }}</td>
        </tr>
        <tr>
            <td style="padding: 3pt;"><strong>{{ __('documents.attend_cert.absences', [], $lang) }}</strong></td>
            <td style="padding: 3pt; text-align: right;">{{ $c['absences'] }}</td>
        </tr>
        <tr style="border-top: 0.7pt solid #333;">
            <td style="padding: 3pt;"><strong>{{ __('documents.attend_cert.rate', [], $lang) }}</strong></td>
            <td style="padding: 3pt; text-align: right;"><strong>{{ $c['rate_percent'] }}%</strong></td>
        </tr>
    </table>

    @include('documents.students._stamp')
    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
