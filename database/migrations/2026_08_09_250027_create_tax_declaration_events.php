<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * docs/plans/phase-05.md migration 250027 - DELIBERATE NO-OP, kept as a
 * documentation migration rather than renumbering (plan risk 3).
 *
 * The plan reserved this slot for registering declaration posting events
 * (`tax.tva.declared`, `tax.tva.settled`, `tax.credit.carried_forward`,
 * `tax.declaration.generated/.filed`) IF posting-rule events were free
 * strings. They are not: `Accounting\Domain\PostingEvent` is a CLOSED enum
 * whose §11.2 catalogue already carries the tax settlement events under
 * their 02-accounting names -
 *
 *   - `tax.vat.declared`        (PostingEvent::TaxVatDeclared)
 *   - `tax.remitted`            (PostingEvent::TaxRemitted)
 *   - `tax.provision.recognized`(PostingEvent::TaxProvisionRecognized)
 *
 * The F5 declaration Actions post through those existing cases via
 * PostFromEvent (the single posting path); adding parallel event names
 * would create a second spelling of the same economic event, and adding
 * enum cases is an Accounting-owner change outside this agent's scope.
 * `tax.declaration.generated/.filed` are module lifecycle notifications,
 * not posting events - they live in the audit log, not the posting-rule
 * catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty - see the class docblock.
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
