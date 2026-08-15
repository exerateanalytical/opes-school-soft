{{-- One labelled control: label, the control itself, helper text saying what
     the value AFFECTS, and an inline error. Pass `:error="$errors->first($key)"`
     from the screen - the component does not guess the error-bag key, because
     these screens map camelCase properties onto snake_case columns and the
     two never line up. --}}
@props([
    'label',
    'hint' => null,
    'error' => null,
    'span' => 1,
])
<label @class(['block text-sm', 'sm:col-span-2' => (int) $span === 2])>
    <span class="mb-1 block font-medium text-charcoal">{{ $label }}</span>
    {{ $slot }}
    @if ($hint !== null)
        <span class="mt-1 block text-xs text-text-secondary">{{ $hint }}</span>
    @endif
    @if ($error !== null && $error !== '')
        <span class="mt-1 block text-xs font-medium text-danger-text">{{ $error }}</span>
    @endif
</label>
