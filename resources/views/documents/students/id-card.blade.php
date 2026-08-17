{{-- docs/specs/10-documents.md §12.1 - Student ID Card / Carte d'élève.
     CR80, landscape, double-sided: front and back are two separate faces in
     ONE render, split by a page break, because dompdf paginates a single
     PDF stream and there is no second "page" input to this pipeline.

     ⚠ Deviation D3 (§2.2.1 / §3): the school crest (school_header, via
     $school['branding']) is the ONLY emblem this card carries. There is no
     field anywhere in $payload for a national coat of arms or a ministry
     seal, and none must ever be added here.

     Blood group is DELIBERATELY absent: SchoolProfile.id_card_show_blood_group
     does not exist in this codebase (see the seed migration's docblock), so
     rather than invent the setting the card omits the field outright.

     $payload['card'] was frozen at issue (receipt pattern, like GATE-PASS).
     The QR block renders only once $document['qr_token'] is non-null, which
     RenderDocument does not yet populate (D2) - so today this area is blank,
     never a PII leak, and LeaveApplication/GatePass's own test pattern of
     asserting "no PII in the HTML" holds regardless. --}}
@extends('documents.layout')

@php
    $c = $payload['card'];
    $lang = $document['language'];
@endphp

@section('content')
    <div class="doc-no-break" style="width: 100%;">
        {{-- FRONT --}}
        <table style="width: 100%;">
            <tr>
                <td style="width: 30%; vertical-align: top; text-align: center;">
                    @if (!empty($school['branding']['crest_uri']))
                        <img src="{{ $school['branding']['crest_uri'] }}" alt="" style="height: 34pt;"><br>
                    @endif
                    <div style="font-size: 7pt; font-weight: bold;">
                        {{ $lang === 'fr' ? ($school['name_fr'] ?: $school['name']) : ($school['name'] ?: $school['name_fr']) }}
                    </div>
                    @if (!empty($c['photo_path']))
                        <img src="{{ $c['photo_path'] }}" alt="" style="width: 60pt; height: 80pt; object-fit: cover; margin-top: 4pt; border: 0.5pt solid #333;">
                    @endif
                </td>
                <td style="width: 70%; vertical-align: top;">
                    <table class="doc-small">
                        <tr><td colspan="2" style="font-weight: bold; font-size: 9pt;">{{ $c['name'] }}</td></tr>
                        <tr>
                            <td>{{ __('documents.id_card.card_no', [], $lang) }}:</td>
                            <td>{{ $document['serial'] ?? '' }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('documents.id_card.class_group', [], $lang) }}:</td>
                            <td>{{ $c['class_group'] }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('documents.id_card.admission_no', [], $lang) }}:</td>
                            <td>{{ $c['admission_no_canonical'] }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('documents.id_card.date_of_birth', [], $lang) }}:</td>
                            <td>{{ $c['date_of_birth'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div style="page-break-before: always;"></div>

    {{-- BACK --}}
    <div class="doc-no-break" style="width: 100%;">
        <table class="doc-small" style="width: 100%;">
            <tr>
                <td>{{ __('documents.id_card.admission_no', [], $lang) }}:</td>
                <td>{{ $c['admission_no_canonical'] }}</td>
            </tr>
            <tr>
                <td>{{ __('documents.id_card.class_group', [], $lang) }}:</td>
                <td>{{ $c['class_group'] }}</td>
            </tr>
            <tr>
                <td>{{ __('documents.id_card.academic_session', [], $lang) }}:</td>
                <td>{{ $c['academic_session'] }}</td>
            </tr>
            @if (!empty($c['section']))
                <tr>
                    <td>{{ __('documents.id_card.section', [], $lang) }}:</td>
                    <td>{{ $c['section'] }}</td>
                </tr>
            @endif
            <tr>
                <td>{{ __('documents.id_card.date_issued', [], $lang) }}:</td>
                <td>{{ $document['issued_at'] ?? '' }}</td>
            </tr>
            <tr>
                <td>{{ __('documents.id_card.valid_until', [], $lang) }}:</td>
                <td>{{ $c['valid_until'] }}</td>
            </tr>
        </table>

        <div style="font-size: 6pt; margin-top: 4pt;">
            <strong>{{ __('documents.id_card.terms_title', [], $lang) }}</strong>
            <ol style="margin: 2pt 0 0 10pt; padding: 0;">
                @foreach (__('documents.id_card.terms', [], $lang) as $term)
                    <li>{{ $term }}</li>
                @endforeach
            </ol>
        </div>

        @if (!empty($c['barcode_data_uri']))
            <div class="doc-center" style="margin-top: 6pt;">
                <img src="{{ $c['barcode_data_uri'] }}" alt="" style="height: 24pt;"><br>
                <span style="font-size: 6pt; letter-spacing: 1pt;">{{ $c['admission_no_canonical'] }}</span>
            </div>
        @endif

        @include('documents.blocks.signature_block')
        @include('documents.blocks.qr_block')
    </div>
@endsection
