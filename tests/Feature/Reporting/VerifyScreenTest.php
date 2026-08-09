<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Livewire\Verify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/P13QrHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/10-documents.md 17.2 - the in-app verification screen at
 * /documents/verify: the four-state answer plus template, issue date and
 * issuer; every failure the SAME generic NOT FOUND; noindex on the response.
 */

it('requires authentication', function () {
    get('/documents/verify')->assertRedirect('/login');
});

it('renders for any authenticated user, noindex, with no student data', function () {
    p13qrUserAs(Role::FrontDesk);

    get('/documents/verify')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee(__('verify.title'));
});

it('answers VALID with template, issue date and issuer for a genuine token', function () {
    p13qrUserAs(Role::FrontDesk);

    $document = p13qrIssuedDocument();
    $token = p13qrTokenFor($document);

    Livewire::test(Verify::class)
        ->set('token', $token)
        ->call('check')
        ->assertSee(__('verify.status_valid'))
        ->assertSee($document['serial'])
        ->assertSee('Certificate of Completion')
        ->assertSee($document['issued_on'])
        ->assertSee($document['issuer_name']);
});

it('answers REVOKED for a revoked document', function () {
    p13qrUserAs(Role::FrontDesk);

    $document = p13qrIssuedDocument();
    $token = p13qrTokenFor($document);

    DB::table('issued_documents')->where('id', $document['id'])->update([
        'status' => 'revoked',
        'revoked_at' => now(),
        'revoked_reason' => 'Issued in error',
    ]);

    Livewire::test(Verify::class)
        ->set('token', $token)
        ->call('check')
        ->assertSee(__('verify.status_revoked'))
        ->assertDontSee(__('verify.status_valid'));
});

it('answers SUPERSEDED and names the superseding serial', function () {
    p13qrUserAs(Role::FrontDesk);

    $original = p13qrIssuedDocument(['serial' => 'HA/2026/COM/000200']);
    $token = p13qrTokenFor($original);

    $reissue = p13qrIssuedDocument([
        'serial' => 'HA/2026/COM/000201',
        'subject_id' => 424242,
        'snapshot_id' => 424243,
    ]);

    DB::table('issued_documents')->where('id', $original['id'])->update([
        'status' => 'superseded',
        'superseded_by_document_id' => $reissue['id'],
    ]);

    Livewire::test(Verify::class)
        ->set('token', $token)
        ->call('check')
        ->assertSee(__('verify.status_superseded'))
        ->assertSee('HA/2026/COM/000201');
});

it('answers the generic NOT FOUND for structural garbage', function () {
    p13qrUserAs(Role::FrontDesk);

    Livewire::test(Verify::class)
        ->set('token', 'not-a-token-at-all')
        ->call('check')
        ->assertSee(__('verify.status_not_found'))
        ->assertDontSee(__('verify.status_valid'));
});

it('answers the same generic NOT FOUND for a correctly signed token with an unknown serial', function () {
    p13qrUserAs(Role::FrontDesk);

    // Sign a token for a serial that has no issued row: cryptographically
    // fine, locally unknown - indistinguishable from garbage by design.
    $token = p13qrTokenFor([
        'serial' => 'HA/2026/COM/999999',
        'content_hash' => hash('sha256', 'phantom'),
        'issued_on' => '2026-05-04',
    ]);

    Livewire::test(Verify::class)
        ->set('token', $token)
        ->call('check')
        ->assertSee(__('verify.status_not_found'))
        ->assertDontSee(__('verify.detail_issuer'));
});

it('answers NOT FOUND for a tampered token of a real document', function () {
    p13qrUserAs(Role::FrontDesk);

    $document = p13qrIssuedDocument(['serial' => 'HA/2026/COM/000300']);
    $token = p13qrTokenFor($document);

    [$prefix, $payload, $sig] = explode('.', $token);
    $json = base64_decode(strtr($payload, '-_', '+/').str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
    $doctored = str_replace('000300', '000301', (string) $json);
    $forged = $prefix.'.'.rtrim(strtr(base64_encode($doctored), '+/', '-_'), '=').'.'.$sig;

    Livewire::test(Verify::class)
        ->set('token', $forged)
        ->call('check')
        ->assertSee(__('verify.status_not_found'));
});

it('answers NOT FOUND when the token hash prefix does not match the issued row', function () {
    p13qrUserAs(Role::FrontDesk);

    $document = p13qrIssuedDocument(['serial' => 'HA/2026/COM/000400']);

    // Correctly signed, real serial - but pinned to DIFFERENT bytes than the
    // issued artefact. A verifier must not bless it.
    $token = p13qrTokenFor([
        'serial' => $document['serial'],
        'content_hash' => hash('sha256', 'different bytes entirely'),
        'issued_on' => $document['issued_on'],
    ]);

    Livewire::test(Verify::class)
        ->set('token', $token)
        ->call('check')
        ->assertSee(__('verify.status_not_found'));
});

it('rejects an empty submission with a validation message, not a lookup', function () {
    p13qrUserAs(Role::FrontDesk);

    Livewire::test(Verify::class)
        ->set('token', '')
        ->call('check')
        ->assertHasErrors(['token' => 'required']);
});
