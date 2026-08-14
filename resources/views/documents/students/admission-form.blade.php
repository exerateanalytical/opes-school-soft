{{-- docs/specs/10-documents.md §7.1 - Admission Form / Fiche d'admission.
     BLANK-FORM live document: labels always render; values render when
     pre-filled and stay as ruled blanks otherwise. $payload['form'] is
     assembled by Students\Actions\PrintAdmissionForm. Every string comes
     from lang/documents.php (§4.6). --}}
@extends('documents.layout')

@php
    $f = $payload['form'];
    $lang = $document['language'];
    // A pre-filled value, or a ruled blank wide enough to handwrite on.
    $line = static fn (string $value): string => $value !== '' ? e($value) : str_repeat('.', 60);
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 10pt 0;">{{ __('documents.admission_form.title', [], $lang) }}</h2>

    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.admission_form.reference', [], $lang) }}:</strong> {!! $line($f['reference']) !!}</td>
            <td style="width: 50%;"><strong>{{ __('documents.admission_form.matricule', [], $lang) }}:</strong> {!! $line($f['matricule']) !!}</td>
        </tr>
    </table>

    <h3 style="margin: 8pt 0 4pt 0; font-size: 10pt;">{{ __('documents.admission_form.section_student', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    <table class="doc-block doc-small" style="line-height: 2.1;">
        <tr>
            <td colspan="2"><strong>{{ __('documents.admission_form.full_name', [], $lang) }}:</strong> {!! $line($f['full_name']) !!}</td>
        </tr>
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.admission_form.date_of_birth', [], $lang) }}:</strong> {!! $line($f['date_of_birth']) !!}</td>
            <td style="width: 50%;"><strong>{{ __('documents.admission_form.gender', [], $lang) }}:</strong>
                {!! $f['gender'] !== '' ? e(__('documents.admission_form.gender_'.$f['gender'], [], $lang)) : str_repeat('.', 30) !!}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.admission_form.place_of_birth', [], $lang) }}:</strong> {!! $line($f['place_of_birth']) !!}</td>
            <td><strong>{{ __('documents.admission_form.nationality', [], $lang) }}:</strong> {!! $line($f['nationality']) !!}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.admission_form.class_applying_for', [], $lang) }}:</strong> {!! $line($f['class_applying_for']) !!}</td>
            <td><strong>{{ __('documents.admission_form.previous_school', [], $lang) }}:</strong> {!! $line($f['previous_school']) !!}</td>
        </tr>
    </table>

    <h3 style="margin: 8pt 0 4pt 0; font-size: 10pt;">{{ __('documents.admission_form.section_guardians', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    @php
        // Two guardian slots minimum (father / mother per the mockup); more
        // when the application carries more.
        $guardianRows = $f['guardians'];
        while (count($guardianRows) < 2) {
            $guardianRows[] = ['name' => '', 'relationship' => '', 'occupation' => '', 'phone' => '', 'email' => ''];
        }
    @endphp

    @foreach ($guardianRows as $guardian)
        <table class="doc-block doc-small" style="line-height: 2.1;">
            <tr>
                <td style="width: 60%;"><strong>{{ __('documents.admission_form.guardian_name', [], $lang) }}:</strong> {!! $line($guardian['name']) !!}</td>
                <td style="width: 40%;"><strong>{{ __('documents.admission_form.guardian_relationship', [], $lang) }}:</strong> {!! $line($guardian['relationship']) !!}</td>
            </tr>
            <tr>
                <td><strong>{{ __('documents.admission_form.guardian_occupation', [], $lang) }}:</strong> {!! $line($guardian['occupation']) !!}</td>
                <td><strong>{{ __('documents.admission_form.guardian_phone', [], $lang) }}:</strong> {!! $line($guardian['phone']) !!}</td>
            </tr>
            <tr>
                <td colspan="2"><strong>{{ __('documents.admission_form.guardian_email', [], $lang) }}:</strong> {!! $line($guardian['email']) !!}</td>
            </tr>
        </table>
        @if (! $loop->last)<div class="doc-rule" style="border-bottom-style: dotted;"></div>@endif
    @endforeach

    <p class="doc-muted doc-small" style="margin-top: 10pt;">{{ __('documents.admission_form.office_use', [], $lang) }}</p>

    @include('documents.blocks.signature_block')
@endsection
