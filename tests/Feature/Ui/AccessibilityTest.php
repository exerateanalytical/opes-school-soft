<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * Every <label for="..."> has a matching id="..." on an input/select/textarea
 * in the same document, and every text-type form control has a label. Uses
 * DOMDocument rather than regex - more robust against attribute ordering and
 * quoting than a hand-rolled pattern.
 */
function assertEveryLabelHasAMatchingInput(string $html): void
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    /** @var list<string> $labelFors */
    $labelFors = [];
    foreach ($xpath->query('//label[@for]') ?: [] as $label) {
        $for = $label->attributes?->getNamedItem('for')?->nodeValue;
        if (is_string($for) && $for !== '') {
            $labelFors[] = $for;
        }
    }

    /** @var list<string> $inputIds */
    $inputIds = [];
    $labelableTypes = ['text', 'email', 'password', 'search', 'tel', 'url', 'number', 'checkbox', 'radio', 'date'];

    foreach ($xpath->query('//input | //select | //textarea') ?: [] as $field) {
        if (! $field instanceof DOMElement) {
            continue;
        }

        $id = $field->attributes->getNamedItem('id')?->nodeValue;
        $type = $field->attributes->getNamedItem('type')->nodeValue ?? 'text';
        $isHidden = $type === 'hidden';

        if (is_string($id) && $id !== '') {
            $inputIds[] = $id;
        }

        if (! $isHidden && in_array($type, $labelableTypes, true) && $field->nodeName !== 'select' && $field->attributes->getNamedItem('disabled') === null) {
            expect(is_string($id) && $id !== '' && in_array($id, $labelFors, true))
                ->toBeTrue("Expected a text-type <{$field->nodeName}> to have a matching <label for>. id=[".($id ?? 'null').']');
        }
    }

    foreach ($xpath->query('//select[not(@disabled)]') ?: [] as $select) {
        $id = $select->attributes?->getNamedItem('id')?->nodeValue;
        expect(is_string($id) && $id !== '' && in_array($id, $labelFors, true))
            ->toBeTrue('Expected <select> to have a matching <label for>. id=['.($id ?? 'null').']');
    }

    foreach ($labelFors as $for) {
        expect(in_array($for, $inputIds, true))
            ->toBeTrue("Label references id=[{$for}] but no element has that id.");
    }
}

function accessibilityAdmin(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole(Role::Administrator->value);

    return $user->fresh() ?? $user;
}

it('has matching labels and inputs on the login page', function () {
    $html = get('/login')->getContent();

    expect($html)->not->toBeFalse();
    assertEveryLabelHasAMatchingInput((string) $html);
});

it('has matching labels and inputs on the users index page', function () {
    actingAs(accessibilityAdmin());

    $html = get('/users')->getContent();

    expect($html)->not->toBeFalse();
    assertEveryLabelHasAMatchingInput((string) $html);
});

it('sets the html lang attribute to english by default', function () {
    $html = (string) get('/login')->getContent();

    expect($html)->toMatch('/<html[^>]*\blang="en"/');
});

it('sets the html lang attribute to french once the locale session is set', function () {
    $admin = accessibilityAdmin();
    actingAs($admin);

    // Drive it through the real mechanism SetLocale reads: session('locale'),
    // set via the /locale POST route rather than App::setLocale() directly.
    post('/locale', ['locale' => 'fr'])->assertRedirect();

    $html = (string) get('/dashboard')->getContent();

    expect($html)->toMatch('/<html[^>]*\blang="fr"/');
});

it('never shows status as colour alone - the pill always carries visible text', function () {
    actingAs(accessibilityAdmin());
    User::factory()->create(['name' => 'Suspended Person', 'status' => 'suspended']);

    $html = (string) get('/users')->getContent();

    expect($html)->toContain('Suspended');
    expect($html)->toContain('Active');
});
