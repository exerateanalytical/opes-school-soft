<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders a sticky save bar, a cancel control and a dirty-state marker', function (): void {
    $html = Blade::render(
        '<x-settings-form title="Test screen" description="A description." cancel="cancel">'
        .'<x-settings-fieldset heading="Group" hint="What this group affects.">'
        .'<x-settings-field label="Field label" hint="What this field affects.">'
        .'<input type="text" wire:model="thing">'
        .'</x-settings-field></x-settings-fieldset></x-settings-form>'
    );

    expect($html)
        ->toContain('Test screen')
        ->toContain('A description.')
        ->toContain('What this group affects.')
        ->toContain('What this field affects.')
        // The dirty-state guard is the whole point: a settings screen that
        // loses a half-typed ministry header on a stray back button is worse
        // than one that never had the field.
        ->toContain('dirty')
        ->toContain('beforeunload')
        ->toContain('wire:submit="save"')
        ->toContain('sticky');
});

it('shows an inline error under the field when one is passed', function (): void {
    $html = Blade::render(
        '<x-settings-field label="Email" error="That is not an email address.">'
        .'<input type="text"></x-settings-field>'
    );

    expect($html)->toContain('That is not an email address.');
});
