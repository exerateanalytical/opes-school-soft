{{-- Test fixture: a minimal snapshot-backed document over the shared shell.
     Renders ONLY from $payload + the platform chrome, which is what makes the
     byte-identity assertions in SnapshotByteIdenticalTest meaningful. --}}
@extends('documents.layout')

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')
    @include('documents.blocks.subject_identity', ['identity' => $payload['identity'] ?? []])

    <h2 class="doc-center">{{ $subject['label'] }}</h2>

    <table class="doc-block">
        @foreach (($payload['lines'] ?? []) as $label => $value)
            <tr>
                <td style="border: 0.5pt solid #999; padding: 3pt;"><strong>{{ $label }}</strong></td>
                <td style="border: 0.5pt solid #999; padding: 3pt;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection
