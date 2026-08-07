<?php

declare(strict_types=1);

// docs/specs/00-core.md 6.2 rule 1: Domain/ imports no Laravel and no Eloquent.
// The Support value objects are the shared kernel and are held to the same bar.
//
// App\Support\Clock\BusinessDate is deliberately absent: it depends on
// Illuminate\Support\Carbon by design and is a framework-aware helper.

arch('money is framework agnostic')
    ->expect('App\Support\Money')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades', 'Illuminate\Http']);

arch('rate is framework agnostic')
    ->expect('App\Support\Rate')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades', 'Illuminate\Http']);

arch('score is framework agnostic')
    ->expect('App\Support\Score')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades', 'Illuminate\Http']);
