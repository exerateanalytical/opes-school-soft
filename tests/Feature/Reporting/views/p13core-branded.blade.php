{{-- Test fixture: the two blocks that carry branding IMAGES, and nothing
     else. p13core-live includes only the header (so it can never exercise the
     signature block) and p13core-snapshot drags in the state header and the QR
     block (so "this document contains no <img> at all" could never be asserted
     against it). This fixture exists so DocumentBrandingRenderTest asserts
     exactly the two blocks under test. --}}
@extends('documents.layout')

@section('content')
    @include('documents.blocks.school_header')

    <h2 class="doc-center">{{ $subject['label'] }}</h2>

    @include('documents.blocks.signature_block')
@endsection
