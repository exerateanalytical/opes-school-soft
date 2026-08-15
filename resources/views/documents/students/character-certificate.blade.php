{{-- docs/specs/10-documents.md §7.8 - Character Certificate / Certificat de
     bonne conduite. Snapshot-backed; payload frozen at issue by
     Students\Actions\PrintCharacterCertificate. The discipline-gate
     override, if any, is deliberately NOT in this payload (§7.8). --}}
@extends('documents.layout')

@php
    $c = $payload['certificate'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 10pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.char_cert.title', [], $lang) }}
    </h2>

    @include('documents.blocks.subject_identity', ['identity' => $c['identity']])

    <p style="margin: 12pt 0; line-height: 1.7; text-align: justify;">
        {{ __('documents.char_cert.body', ['since' => $c['known_since']], $lang) }}
    </p>

    @if ($c['conduct_total_cases'] > 0)
        <p class="doc-small">{{ __('documents.char_cert.conduct_cases', ['count' => $c['conduct_total_cases']], $lang) }}</p>
    @endif

    <p class="doc-small">{{ __('documents.char_cert.issued_on', ['date' => $c['issued_on_date']], $lang) }}</p>

    @include('documents.students._stamp')
    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
