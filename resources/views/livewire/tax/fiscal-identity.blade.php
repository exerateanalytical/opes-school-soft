{{--
    03-tax-procurement §2.4 - fiscal identity, the blocking wizard step.
    Text is deliberately literal (not lang keys): lang/{en,fr}/opes.php is
    owned by Agent F5's wiring pass this phase; localisation lands there.
--}}
<div class="mx-auto max-w-4xl space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">Fiscal identity</h1>
        <p class="text-sm text-charcoal/70">
            These values print on every invoice, receipt and attestation. A document without the NIU is a
            legally deficient document, and the school bears the penalty (03-tax-procurement &sect;2.2).
        </p>
    </div>

    @if ($errorMessage !== '')
        <div class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($successMessage !== '')
        <div class="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
            {{ $successMessage }}
        </div>
    @endif

    @if ($identity !== null && $identity->isConfirmed())
        <div class="rounded border border-border-primary bg-white px-4 py-3 text-sm text-charcoal">
            Confirmed on {{ $identity->fiscal_identity_confirmed_at?->toDateString() }}.
            The NIU is now immutable; corrections require the permission-gated correction procedure
            with a reason and a supporting document.
            <button type="button" wire:click="toggleCorrectionForm" class="ml-2 text-xs font-medium text-charcoal underline">
                {{ $showCorrectionForm ? 'Cancel correction' : 'Correct fiscal identity' }}
            </button>
        </div>

        @if ($showCorrectionForm)
            <form wire:submit="correct" class="space-y-3 rounded border border-amber-300 bg-amber-50 p-4">
                <p class="text-xs text-amber-900">
                    A NIU typo silently propagates onto every printed invoice and filed declaration -
                    this correction is a recorded act, not a routine edit (03-tax-procurement &sect;2.2).
                </p>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Corrected NIU</span>
                    <input type="text" maxlength="14" wire:model="correctionNiu"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Reason</span>
                    <textarea wire:model="correctionReason" rows="2"
                              class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Supporting document reference</span>
                    <input type="text" wire:model="correctionSupportingDocumentReference"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                    Correct fiscal identity
                </button>
            </form>
        @endif
    @else
        <div class="rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Not confirmed yet &mdash; printing invoices, receipts and attestations is blocked until the
            fiscal identity is complete and confirmed.
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <fieldset class="space-y-3 rounded border border-border-primary bg-white p-4">
            <legend class="px-1 text-sm font-semibold text-charcoal">Legal identity</legend>

            <label for="fi-legal-name" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Legal name (as registered)</span>
                <input id="fi-legal-name" type="text" wire:model="legalName"
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="fi-legal-form" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Legal form</span>
                <select id="fi-legal-form" wire:model="legalForm"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">&mdash;</option>
                    @foreach ($legalForms as $form)
                        <option value="{{ $form->value }}">{{ str_replace('_', ' ', $form->value) }}</option>
                    @endforeach
                </select>
            </label>

            <div class="grid gap-3 sm:grid-cols-3">
                <label for="fi-rccm-number" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">RCCM number</span>
                    <input id="fi-rccm-number" type="text" wire:model="rccmNumber"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-rccm-registry" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">RCCM registry (greffe)</span>
                    <input id="fi-rccm-registry" type="text" wire:model="rccmRegistry"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-rccm-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">RCCM registered on</span>
                    <input id="fi-rccm-date" type="date" wire:model="rccmRegisteredOn"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>
            <p class="text-xs text-charcoal/60">
                Mandatory for commercial forms (SARL, SA, GIE, &eacute;tablissement individuel).
            </p>
        </fieldset>

        <fieldset class="space-y-3 rounded border border-border-primary bg-white p-4">
            <legend class="px-1 text-sm font-semibold text-charcoal">Tax identity</legend>

            <div class="grid gap-3 sm:grid-cols-2">
                <label for="fi-niu" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">NIU (Num&eacute;ro Identifiant Unique)</span>
                    <input id="fi-niu" type="text" maxlength="14" wire:model="niu"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-niu-issued" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">NIU issued on</span>
                    <input id="fi-niu-issued" type="date" wire:model="niuIssuedOn"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <label for="fi-centre-code" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Tax centre code</span>
                    <input id="fi-centre-code" type="text" wire:model="taxCentreCode"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-centre-name" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Tax centre name</span>
                    <input id="fi-centre-name" type="text" wire:model="taxCentreName"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-centre-type" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Tax centre type</span>
                    <select id="fi-centre-type" wire:model="taxCentreType"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">&mdash;</option>
                        @foreach ($taxCentreTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->value }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="text-xs text-charcoal/60">
                The centre type selects the DSF due date: DGE 15 March, CIME 15 April, others 15 May.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label for="fi-regime" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Tax regime</span>
                    <select id="fi-regime" wire:model="taxRegime"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">&mdash;</option>
                        @foreach ($taxRegimes as $regime)
                            <option value="{{ $regime->value }}">{{ $regime->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label for="fi-regime-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Regime effective from</span>
                    <input id="fi-regime-from" type="date" wire:model="taxRegimeEffectiveFrom"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label for="fi-tva" class="flex items-center gap-2 pt-4">
                    <input id="fi-tva" type="checkbox" wire:model="isTvaRegistered"
                           class="rounded border-border-primary text-primary focus:ring-primary"/>
                    <span class="text-sm text-charcoal">TVA-registered (assujetti)</span>
                </label>
                <label for="fi-tva-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">TVA registered from</span>
                    <input id="fi-tva-from" type="date" wire:model="tvaRegisteredFrom"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>
            <p class="text-xs text-charcoal/60">
                TVA registration requires the r&eacute;gime r&eacute;el; whether the r&eacute;gime
                simplifi&eacute; may register is unverified, so only r&eacute;el is accepted.
            </p>
        </fieldset>

        <fieldset class="space-y-3 rounded border border-border-primary bg-white p-4">
            <legend class="px-1 text-sm font-semibold text-charcoal">Ministry accreditation</legend>
            <p class="text-xs text-charcoal/60">
                The autorisation d&apos;ouverture conditions the TVA exemption on tuition and boarding
                (&sect;5.2). Without it the school cannot invoice those lines exempt.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label for="fi-accr-no" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Accreditation number</span>
                    <input id="fi-accr-no" type="text" wire:model="ministryAccreditationNumber"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-accr-auth" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Authority (MINESEC, MINEDUB, MINEFOP, MINESUP, other)</span>
                    <input id="fi-accr-auth" type="text" wire:model="ministryAccreditationAuthority"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label for="fi-accr-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Accreditation date</span>
                    <input id="fi-accr-date" type="date" wire:model="ministryAccreditationDate"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label for="fi-accr-exp" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Expires on (empty = indefinite)</span>
                    <input id="fi-accr-exp" type="date" wire:model="ministryAccreditationExpiresOn"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-2 rounded border border-border-primary bg-white p-4">
            <legend class="px-1 text-sm font-semibold text-charcoal">Fiscal year</legend>
            <p class="text-sm text-charcoal">
                Exercice end: <strong>31 December</strong> (read-only). OHADA fixes the exercice at
                1 January &ndash; 31 December; an irregular first exercice is expressed on the fiscal
                year itself, never here.
            </p>
        </fieldset>

        <div class="space-y-3 rounded border border-border-primary bg-white p-4">
            <label for="fi-confirm" class="flex items-start gap-2">
                <input id="fi-confirm" type="checkbox" wire:model="confirmChecked"
                       class="mt-0.5 rounded border-border-primary text-primary focus:ring-primary"/>
                <span class="text-sm text-charcoal">
                    I confirm these values match the school&apos;s registration documents.
                </span>
            </label>

            <button type="submit"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                Confirm fiscal identity
            </button>
        </div>
    </form>
</div>
