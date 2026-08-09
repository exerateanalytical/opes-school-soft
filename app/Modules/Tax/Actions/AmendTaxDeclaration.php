<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.1 / §7.5 - the amendment path: a
 * FILED declaration is never edited and its year is never reopened; the
 * remedy is an amending declaration (`amends_declaration_id`, UNIQUE -
 * one amendment per original, chained) regenerated from TODAY'S ledger,
 * plus correcting entries in the open year.
 *
 * The amendment row occupies the original's period slot in the unique
 * key, so the "one declaration per period" backstop holds for originals
 * while the chain stays representable. The original flips to `amended`
 * when the amendment is FILED (FileTaxDeclaration), not before.
 */
final class AmendTaxDeclaration
{
    public const PERMISSION = Permission::TaxDeclare->value;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly GenerateTvaDeclaration $tvaGenerator,
        private readonly GenerateWithholdingDeclaration $withholdingGenerator,
    ) {}

    public function handle(int $originalDeclarationId, string $reason, Actor $actor): TaxDeclaration
    {
        Gate::authorize(self::PERMISSION);

        if (trim($reason) === '') {
            throw new DomainException('Amending a filed declaration requires a reason.');
        }

        /** @var TaxDeclaration $original */
        $original = TaxDeclaration::query()->findOrFail($originalDeclarationId);

        if (! $original->status->isFiled()) {
            throw new DomainException(sprintf(
                'Declaration %s %04d-%02d is %s; only a FILED declaration is amended - an unfiled one is cancelled and regenerated.',
                $original->declaration_type,
                $original->period_year,
                $original->period_month,
                $original->status->value,
            ));
        }

        if ($original->amends_declaration_id !== null) {
            throw new DomainException(
                'This declaration is itself an amendment; amend IT (the chain continues from the latest link), not the original twice.'
            );
        }

        $amendment = match ($original->declaration_type) {
            DeclarationTypeCode::TvaMonthly->value => $this->tvaGenerator->handle(
                $original->period_year, $original->period_month, $actor, $original,
            ),
            DeclarationTypeCode::WithholdingMonthly->value => $this->withholdingGenerator->handle(
                $original->period_year, $original->period_month, $actor, $original,
            ),
            default => throw new DomainException(sprintf(
                'No amendment generator exists for declaration type %s.',
                $original->declaration_type,
            )),
        };

        $amendment->forceFill([
            'notes' => trim(($amendment->notes !== null ? $amendment->notes."\n" : '').'Amendment reason: '.trim($reason)),
        ])->save();

        $this->audit->handle(
            action: AuditAction::Created,
            module: 'Tax',
            auditableType: TaxDeclaration::class,
            auditableId: (int) $amendment->getKey(),
            after: [
                'amends_declaration_id' => $original->id,
                'reason' => trim($reason),
            ],
            actor: $actor,
        );

        return $amendment->refresh();
    }
}
