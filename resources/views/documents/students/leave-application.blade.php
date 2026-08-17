{{-- docs/specs/10-documents.md §11.3 - Leave Application / Demande de congé
     (STUDENT variant only - see the seed migration's header for the staff
     variant's deferral). BLANK-FORM live document: labels always render;
     values render when pre-filled and stay as ruled blanks otherwise, exactly
     like admission-form.blade.php. $payload['form'] is assembled by
     Students\Actions\PrintLeaveApplication. --}}
@extends('documents.layout')

@php
    $f = $payload['form'];
    $lang = $document['language'];
    $line = static fn (string $value): string => $value !== '' ? e($value) : str_repeat('.', 60);
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 10pt 0;">{{ __('documents.leave_application.title', [], $lang) }}</h2>

    <table class="doc-block doc-small" style="line-height: 2.1;">
        <tr>
            <td colspan="2"><strong>{{ __('documents.leave_application.student_name', [], $lang) }}:</strong> {!! $line($f['student_name']) !!}</td>
        </tr>
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.leave_application.class_group', [], $lang) }}:</strong> {!! $line($f['class_group']) !!}</td>
            <td style="width: 50%;"><strong>{{ __('documents.leave_application.date_requested', [], $lang) }}:</strong> {!! $line($f['date_requested']) !!}</td>
        </tr>
    </table>

    <div class="doc-rule"></div>

    <table class="doc-block doc-small" style="line-height: 2.1;">
        <tr>
            <td><strong>{{ __('documents.leave_application.reason', [], $lang) }}:</strong></td>
        </tr>
        <tr>
            <td>{!! $line($f['reason']) !!}</td>
        </tr>
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.leave_application.from', [], $lang) }}:</strong> {!! $line($f['from']) !!}</td>
            <td style="width: 50%;"><strong>{{ __('documents.leave_application.to', [], $lang) }}:</strong> {!! $line($f['to']) !!}</td>
        </tr>
    </table>

    <p class="doc-muted doc-small" style="margin-top: 10pt;">{{ __('documents.leave_application.footer_note', [], $lang) }}</p>

    @include('documents.blocks.signature_block')
@endsection
