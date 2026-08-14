{{-- docs/specs/10-documents.md §7.7 - School Leaving Certificate /
     Certificat de fin de scolarité. Snapshot-backed; payload frozen at
     issue by Students\Actions\PrintLeavingCertificate. --}}
@extends('documents.layout')

@php
    $c = $payload['certificate'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 10pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.leaving_cert.title', [], $lang) }}
    </h2>

    @include('documents.blocks.subject_identity', ['identity' => $c['identity']])

    <p style="margin: 12pt 0; line-height: 1.7; text-align: justify;">
        {{ __('documents.leaving_cert.body', [
            'admitted' => $c['admitted_on'],
            'left' => $c['left_on'],
            'class' => $c['identity']['class_group'],
            'level' => $c['level'],
        ], $lang) }}
    </p>

    <p class="doc-small" style="line-height: 1.6;">
        @if ($c['conduct_is_clear'])
            {{ __('documents.leaving_cert.conduct_clear', [], $lang) }}
        @else
            {{ __('documents.leaving_cert.conduct_cases', ['count' => $c['conduct_total_cases']], $lang) }}
        @endif
    </p>

    @include('documents.students._stamp')
    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
