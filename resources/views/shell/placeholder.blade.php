@component('layouts.app')
    {{-- TEMPORARY. Phase 0D task 3 builds the shell; the screens that live
         inside it arrive with their own tasks. This view exists so a route can
         prove the shell renders before its real occupant is written. --}}
    <h1 class="text-xl font-semibold text-charcoal">{{ $heading }}</h1>
    <p class="mt-2 max-w-prose text-sm text-charcoal/70">{{ $body }}</p>
@endcomponent
