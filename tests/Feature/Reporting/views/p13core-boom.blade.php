{{-- Test fixture: a template whose render always fails, for the
     "a failed render consumes no series number" assertion (10-documents 4.5). --}}
@php
    throw new RuntimeException('p13core boom: this template always fails to render.');
@endphp
