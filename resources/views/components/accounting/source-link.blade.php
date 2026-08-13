@props(['reference'])

{{--
    Renders an App\Support\Ledger\SourceReference,
    docs/specs/2026-08-12-accounting-finance-architecture.md §6.

    An inert reference is a first-class case, not a failure: a manual journal
    has no source document, and a document type with no viewing screen (student
    invoices today) legitimately cannot be linked. Both render as plain text so
    the drill-down chain always terminates visibly, never in a dead link.
--}}
@if ($reference->isResolvable())
    <a href="{{ $reference->url() }}" class="text-primary hover:underline">{{ $reference->label() }}</a>
@else
    <span class="text-charcoal/50">{{ $reference->label() }}</span>
@endif
