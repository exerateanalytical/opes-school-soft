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

/*
 * The same bar for every module's Domain namespace - 00-core 6.2 rule 1 is
 * about Domain/ generally, not only the shared kernel, and 01-assessment 2.2
 * leans on it hard: GradingPipeline is "a pure class with no Laravel and no
 * Eloquent", and every number on a report card flows through it. Until this
 * rule existed, that purity was a convention the pipeline's author happened
 * to follow rather than something a stray `DB::` import would fail the build
 * for. Enums using the __() helper pass: the helper is a global function,
 * not an import, and a label lookup is not a framework dependency in the
 * sense this rule exists to forbid (queries, facades, HTTP).
 *
 * Enumerated per module rather than one 'App\Modules' expectation so a
 * failure names the offending module, and so adding a new module makes the
 * omission conspicuous in review.
 */
foreach ([
    'Academics', 'Accounting', 'Admissions', 'Assessment',
    'Guardians', 'Identity', 'Operations', 'SchoolProfile', 'Students',
] as $module) {
    arch(strtolower($module).' domain is framework agnostic')
        ->expect('App\Modules\\'.$module.'\Domain')
        ->not->toUse([
            'Illuminate\Database',
            'Illuminate\Support\Facades',
            'Illuminate\Http',
            'Illuminate\Database\Eloquent',
        ]);
}
