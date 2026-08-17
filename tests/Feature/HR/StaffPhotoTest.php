<?php

declare(strict_types=1);

use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\HR\Actions\SetStaffPhoto;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * The photograph lives on the PRIVATE default disk, not `public` - see
 * SetStaffPhoto. Faking the default disk is what makes these assertions about
 * the real storage path rather than about a disk nothing reads.
 */
function staffPhotoDisk(): string
{
    return (string) config('filesystems.default');
}

beforeEach(function (): void {
    // Both, so a test can assert the bytes did NOT land on the public one.
    Storage::fake(staffPhotoDisk());
    Storage::fake('public');
});

/**
 * @return array{0: User, 1: StaffMember}
 */
function staffPhotoFixture(): array
{
    (new RolePermissionSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole(Role::Administrator->value);

    actingAs($admin);

    $staff = app(HireStaffMember::class)->handle(
        firstName: 'Ada',
        lastName: 'Ngu',
        gender: 'female',
        dateOfBirth: '1992-04-04',
        phone: '677111222',
        hiredOn: '2026-01-01',
    );

    return [$admin, $staff];
}

function staffPhotoBytes(string $name, int $width = 240, int $height = 240): string
{
    return (string) file_get_contents(
        (string) UploadedFile::fake()->image($name, $width, $height)->getRealPath()
    );
}

it('stores a staff photo, persists the path and writes the file', function (): void {
    [$admin, $staff] = staffPhotoFixture();

    $path = app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('ada.png'),
        'png',
        $admin->toAuditActor(),
    );

    expect(DB::table('staff_members')->where('id', $staff->getKey())->value('photo_path'))->toBe($path);
    Storage::disk(staffPhotoDisk())->assertExists($path);
});

it('deletes the previous file when a photo is replaced', function (): void {
    [$admin, $staff] = staffPhotoFixture();
    $action = app(SetStaffPhoto::class);

    $first = $action->set((int) $staff->getKey(), staffPhotoBytes('one.png', 240, 240), 'png', $admin->toAuditActor());
    $second = $action->set((int) $staff->getKey(), staffPhotoBytes('two.png', 200, 300), 'png', $admin->toAuditActor());

    expect($second)->not->toBe($first);
    Storage::disk(staffPhotoDisk())->assertMissing($first);
    Storage::disk(staffPhotoDisk())->assertExists($second);
});

it('keeps a file another staff member still references', function (): void {
    [$admin, $staff] = staffPhotoFixture();
    $action = app(SetStaffPhoto::class);

    $other = app(HireStaffMember::class)->handle(
        firstName: 'Bih',
        lastName: 'Tabi',
        gender: 'female',
        dateOfBirth: '1994-05-05',
        phone: '677333444',
        hiredOn: '2026-01-01',
    );

    // Identical bytes content-hash to the SAME path, so both rows point at
    // one file: clearing one must not blank the other's ID card.
    $bytes = staffPhotoBytes('shared.png');

    $shared = $action->set((int) $staff->getKey(), $bytes, 'png', $admin->toAuditActor());
    $alsoShared = $action->set((int) $other->getKey(), $bytes, 'png', $admin->toAuditActor());

    expect($alsoShared)->toBe($shared);

    $action->remove((int) $staff->getKey(), $admin->toAuditActor());

    Storage::disk(staffPhotoDisk())->assertExists($shared);
    expect(DB::table('staff_members')->where('id', $other->getKey())->value('photo_path'))->toBe($shared);
});

it('clears the column and deletes the file on remove', function (): void {
    [$admin, $staff] = staffPhotoFixture();
    $action = app(SetStaffPhoto::class);

    $path = $action->set((int) $staff->getKey(), staffPhotoBytes('gone.png'), 'png', $admin->toAuditActor());

    $action->remove((int) $staff->getKey(), $admin->toAuditActor());

    expect(DB::table('staff_members')->where('id', $staff->getKey())->value('photo_path'))->toBeNull();
    Storage::disk(staffPhotoDisk())->assertMissing($path);
});

it('refuses a user without staff.manage', function (): void {
    [, $staff] = staffPhotoFixture();

    // A bare user holds no HR ability at all.
    actingAs(User::factory()->create());

    app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('nope.png'),
        'png',
        Actor::system(),
    );
})->throws(AuthorizationException::class);

/*
 * ── Privacy: the bytes are not on a publicly served disk ────────────────────
 *
 * The `public` disk is symlinked into the web root and served with no
 * authentication. StoredImage defaults there because that is where the school
 * CREST belongs; a photograph of a named employee must not follow it.
 */

it('never writes a staff photo to the public disk or the branding directory', function (): void {
    [$admin, $staff] = staffPhotoFixture();

    $path = app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('private.png'),
        'png',
        $admin->toAuditActor(),
    );

    expect($path)->toStartWith(SetStaffPhoto::DIRECTORY.'/')
        ->and($path)->not->toStartWith(StoredImage::DIRECTORY.'/');

    Storage::disk(staffPhotoDisk())->assertExists($path);
    Storage::disk('public')->assertMissing($path);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses the staff photo endpoint to an unauthenticated request', function (): void {
    [$admin, $staff] = staffPhotoFixture();

    app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('ada.png'),
        'png',
        $admin->toAuditActor(),
    );

    auth()->logout();

    $this->get(route('hr.staff.photo', ['staffMember' => $staff->getKey()]))
        ->assertRedirect('/login');
});

it('refuses the staff photo endpoint to a user without staff.view', function (): void {
    [$admin, $staff] = staffPhotoFixture();

    app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('ada.png'),
        'png',
        $admin->toAuditActor(),
    );

    // A bare user holds no HR ability at all.
    actingAs(User::factory()->create());

    $this->get(route('hr.staff.photo', ['staffMember' => $staff->getKey()]))
        ->assertForbidden();
});

it('streams the staff photo to an authorised user', function (): void {
    [$admin, $staff] = staffPhotoFixture();

    app(SetStaffPhoto::class)->set(
        (int) $staff->getKey(),
        staffPhotoBytes('ada.png'),
        'png',
        $admin->toAuditActor(),
    );

    $response = $this->get(route('hr.staff.photo', ['staffMember' => $staff->getKey()]));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
