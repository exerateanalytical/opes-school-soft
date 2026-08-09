{{-- Test fixture: a minimal LIVE document (class-list shaped): renders the
     caller-assembled rows and the Generated-on footer the layout provides. --}}
@extends('documents.layout')

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center">{{ $subject['label'] }}</h2>

    <table class="doc-block">
        @foreach (($payload['rows'] ?? []) as $row)
            <tr>
                <td style="border: 0.5pt solid #999; padding: 3pt;">{{ $row }}</td>
            </tr>
        @endforeach
    </table>
@endsection
