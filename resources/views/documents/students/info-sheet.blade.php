{{-- docs/specs/10-documents.md §7.2 - Student Information Sheet / Fiche de
     renseignements. LIVE working sheet; the encrypted fields it prints were
     decrypted inside Students\Actions\PrintStudentInfoSheet and exist only
     in this render (§9.5). Guardian signature verifies the data. --}}
@extends('documents.layout')

@php
    $s = $payload['sheet'];
    $lang = $document['language'];
    $dash = '—';
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 10pt 0;">{{ __('documents.info_sheet.title', [], $lang) }}</h2>

    @include('documents.blocks.subject_identity', ['identity' => [
        'name' => $s['full_name'],
        'matricule' => $s['matricule'],
        'class_group' => $s['class_group'],
        'section' => $s['section'],
        'academic_year' => $s['academic_year'],
        'date_of_birth' => $s['date_of_birth'],
    ]])

    <h3 style="margin: 8pt 0 4pt 0; font-size: 10pt;">{{ __('documents.info_sheet.personal', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    <table class="doc-block doc-small" style="line-height: 1.9;">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.info_sheet.admission_no', [], $lang) }}:</strong> {{ $s['admission_no'] }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.info_sheet.place_of_birth', [], $lang) }}:</strong> {{ $s['place_of_birth'] !== '' ? $s['place_of_birth'] : $dash }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.info_sheet.gender', [], $lang) }}:</strong> {{ __('documents.admission_form.gender_'.$s['gender'], [], $lang) }}</td>
            <td><strong>{{ __('documents.info_sheet.nationality', [], $lang) }}:</strong> {{ $s['nationality'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.info_sheet.religion', [], $lang) }}:</strong> {{ $s['religion'] !== '' ? $s['religion'] : $dash }}</td>
            <td><strong>{{ __('documents.info_sheet.national_id', [], $lang) }}:</strong> {{ $s['national_id'] !== '' ? $s['national_id'] : $dash }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.info_sheet.blood_group', [], $lang) }}:</strong> {{ $s['blood_group'] !== '' ? $s['blood_group'] : $dash }}</td>
            <td><strong>{{ __('documents.info_sheet.genotype', [], $lang) }}:</strong> {{ $s['genotype'] !== '' ? $s['genotype'] : $dash }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>{{ __('documents.info_sheet.address', [], $lang) }}:</strong> {{ $s['address'] !== '' ? $s['address'] : $dash }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.info_sheet.phone', [], $lang) }}:</strong> {{ $s['phone'] !== '' ? $s['phone'] : $dash }}</td>
            <td><strong>{{ __('documents.info_sheet.email', [], $lang) }}:</strong> {{ $s['email'] !== '' ? $s['email'] : $dash }}</td>
        </tr>
    </table>

    <h3 style="margin: 8pt 0 4pt 0; font-size: 10pt;">{{ __('documents.info_sheet.medical', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    @if ($s['medical'] === [])
        <p class="doc-small doc-muted">{{ __('documents.info_sheet.medical_none', [], $lang) }}</p>
    @else
        <table class="doc-block doc-small">
            <thead>
                <tr style="border-bottom: 0.7pt solid #333;">
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.info_sheet.medical_condition', [], $lang) }}</th>
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.info_sheet.medical_summary', [], $lang) }}</th>
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.info_sheet.medical_severity', [], $lang) }}</th>
                    <th style="text-align: left; padding: 3pt;">{{ __('documents.info_sheet.medical_emergency', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($s['medical'] as $row)
                    <tr>
                        <td style="padding: 3pt;">{{ $row['condition_type'] }}</td>
                        <td style="padding: 3pt;">{{ $row['summary'] }}</td>
                        <td style="padding: 3pt;">{{ $row['severity'] }}</td>
                        <td style="padding: 3pt;">{{ __($row['is_emergency_relevant'] ? 'documents.info_sheet.yes' : 'documents.info_sheet.no', [], $lang) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3 style="margin: 8pt 0 4pt 0; font-size: 10pt;">{{ __('documents.info_sheet.emergency_contact', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    <table class="doc-block doc-small" style="line-height: 1.9;">
        <tr>
            <td style="width: 40%;"><strong>{{ __('documents.info_sheet.emergency_name', [], $lang) }}:</strong> {{ $s['emergency_contact_name'] !== '' ? $s['emergency_contact_name'] : $dash }}</td>
            <td style="width: 30%;"><strong>{{ __('documents.info_sheet.emergency_relationship', [], $lang) }}:</strong> {{ $s['emergency_contact_relationship'] !== '' ? $s['emergency_contact_relationship'] : $dash }}</td>
            <td style="width: 30%;"><strong>{{ __('documents.info_sheet.emergency_phone', [], $lang) }}:</strong> {{ $s['emergency_contact_phone'] !== '' ? $s['emergency_contact_phone'] : $dash }}</td>
        </tr>
    </table>

    <p class="doc-small" style="margin-top: 10pt;">
        {{ __('documents.info_sheet.verify_notice', [], $lang) }}
        <span class="doc-muted">({{ __('documents.info_sheet.as_of', [], $lang) }} {{ $s['as_of'] }})</span>
    </p>

    @include('documents.blocks.signature_block')
@endsection
