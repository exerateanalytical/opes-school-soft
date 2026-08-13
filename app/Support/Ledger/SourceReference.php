<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * A link from a journal entry to the document that caused it.
 *
 * Lives in the shared kernel for the same reason App\Support\Audit\Actor
 * does: docs/specs/00-core.md 6.2 forbids a module importing another
 * module's Models, but the ledger must be able to name a document that
 * belongs to Assets, Procurement or Payroll. A plain value object crosses
 * the boundary; a model never does.
 *
 * An unresolvable reference is a first-class case, not an error. A manual
 * journal genuinely has no source document, and a document type with no
 * viewing route (student invoices today) legitimately cannot be linked.
 * Both render as inert labelled text, so the chain always terminates
 * visibly rather than in a broken link or a leaked class name.
 */
final readonly class SourceReference
{
    private function __construct(
        private string $label,
        private ?string $url,
    ) {}

    public static function linked(string $label, string $url): self
    {
        return new self($label, $url);
    }

    public static function inert(string $label): self
    {
        return new self($label, null);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function isResolvable(): bool
    {
        return $this->url !== null;
    }
}
