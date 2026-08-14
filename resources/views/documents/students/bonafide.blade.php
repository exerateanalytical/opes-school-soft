{{-- docs/specs/10-documents.md §7.10 - Bonafide Student Certificate /
     Attestation d'inscription. Short attestation; payload frozen at issue
     by Students\Actions\PrintBonafideCertificate. --}}
@extends('documents.layout')

@php
    $c = $payload['certificate'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 10pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.bonafide.title', [], $lang) }}
    </h2>

    @include('documents.blocks.subject_identity', ['identity' => $c['identity']])

    <p style="margin: 12pt 0; line-height: 1.7; text-align: justify;">
        {{ __('documents.bonafide.body', [
            'class' => $c['identity']['class_group'],
            'level' => $c['level'],
            'year' => $c['academic_year'],
            'enrolled' => $c['enrolled_on'],
        ], $lang) }}
    </p>

    <p class="doc-small">{{ __('documents.bonafide.purpose', [], $lang) }}</p>

    @include('documents.students._stamp')
    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
