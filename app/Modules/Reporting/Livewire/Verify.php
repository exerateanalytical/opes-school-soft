<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Livewire;

use App\Modules\Reporting\Actions\VerifyDocumentQrToken;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /documents/verify - the in-app (LAN) verification screen from
 * docs/specs/10-documents.md 17.2: paste or scan an OPES1 token, the app
 * validates the signature against its own keys and looks the serial up
 * locally, showing VALID / REVOKED / SUPERSEDED / NOT FOUND plus template,
 * issue date and issuer. No internet involved.
 *
 * Auth-only, no permission: the result contains no student data by
 * construction (the token never carried any), and verifying a certificate
 * someone presents at the front desk is exactly the front desk's job. The
 * route wears MarkNoIndex; every failure renders the same generic NOT FOUND
 * (no "bad signature" vs "no such serial" - 17.2's anti-enumeration rule).
 * There is no search and no listing: the only way in is a whole token.
 */
#[Layout('layouts.app')]
final class Verify extends Component
{
    public string $token = '';

    /** True once a check ran, so the empty state and NOT FOUND differ. */
    public bool $checked = false;

    /** @var array{status: string, serial: string|null, template: string|null, template_fr: string|null, issued_on: string|null, issuer: string|null, superseded_by: string|null}|null */
    public ?array $result = null;

    public function check(VerifyDocumentQrToken $verifier): void
    {
        $this->validate(['token' => 'required|string|max:4096'], [
            'token.required' => __('verify.token_required'),
        ]);

        $outcome = $verifier->handle($this->token);

        $this->checked = true;
        $this->result = [
            'status' => $outcome->status->value,
            'serial' => $outcome->serial,
            'template' => $outcome->templateName,
            'template_fr' => $outcome->templateNameFr,
            'issued_on' => $outcome->issuedOn,
            'issuer' => $outcome->issuerName,
            'superseded_by' => $outcome->supersededBySerial,
        ];
    }

    public function render(): View
    {
        return view('livewire.reporting.verify');
    }
}
