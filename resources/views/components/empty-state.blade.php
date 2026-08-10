@props([
    'message' => '',
])

{{-- An empty region must say why it is empty. A blank panel reads as a broken
     screen, and the operator's next move is a support call. --}}
<div {{ $attributes->merge(['class' => 'rounded border border-dashed border-border-primary bg-white px-6 py-8 text-center']) }}>
    <p class="text-sm text-charcoal/70">{{ $message }}</p>

    @isset($action)
        <div class="mt-4 flex justify-center">
            {{ $action }}
        </div>
    @endisset
</div>
