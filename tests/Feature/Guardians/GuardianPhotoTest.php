<?php

declare(strict_types=1);

use App\Modules\Guardians\Actions\SetGuardianPhoto;
use App\Modules\Guardians\Livewire\Guardians\Show;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * The photograph lives on the DEFAULT disk, not `public` - see
 * SetGuardianPhoto. Faking the default disk is what makes these assertions
 * about the real storage path rather than about a disk nothing reads.
 */
beforeEach(function (): void {
    Storage::fake((string) config('filesystems.default'));
});

/**
 * Named to avoid the shared Pest function namespace: GuardianTest.php and
 * GuardianAuthorizationTest.php already declare guardiansUserAs().
 */
function guardianPhotoUser(bool $withPermission = true): User
{
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate(OpesPermission::GuardiansManage->value, 'web');
    Permission::findOrCreate(OpesPermission::StudentsView->value, 'web');

    $user = User::factory()->create();

    // students.view regardless: it is what the Show screen mounts on, so
    // without it the "no guardians.manage" case would fail on the wrong gate.
    $user->givePermissionTo(OpesPermission::StudentsView->value);

    if ($withPermission) {
        $user->givePermissionTo(OpesPermission::GuardiansManage->value);
    }

    return $user->fresh() ?? $user;
}

it('stores an uploaded photograph and persists its content-hashed path', function (): void {
    actingAs(guardianPhotoUser());

    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->set('photoUpload', UploadedFile::fake()->image('face.jpg', 400, 400))
        ->call('savePhoto')
        ->assertHasNoErrors();

    $path = (string) $guardian->fresh()?->photo_path;

    expect($path)->toStartWith(SetGuardianPhoto::DIRECTORY.'/guardian-')
        ->and(Storage::disk((string) config('filesystems.default'))->exists($path))->toBeTrue();
});

it('refuses a file that is not an allowed image type', function (): void {
    actingAs(guardianPhotoUser());

    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->set('photoUpload', UploadedFile::fake()->create('face.svg', 10, 'image/svg+xml'))
        ->call('savePhoto')
        ->assertHasErrors('photoUpload');

    expect($guardian->fresh()?->photo_path)->toBeNull();
});

it('deletes the photograph a guardian used to hold when it is replaced', function (): void {
    actingAs(guardianPhotoUser());

    $disk = Storage::disk((string) config('filesystems.default'));
    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->set('photoUpload', UploadedFile::fake()->image('one.jpg', 200, 200))
        ->call('savePhoto');

    $first = (string) $guardian->fresh()?->photo_path;

    Livewire::test(Show::class, ['guardian' => $guardian->fresh()])
        ->set('photoUpload', UploadedFile::fake()->image('two.jpg', 320, 240))
        ->call('savePhoto');

    $second = (string) $guardian->fresh()?->photo_path;

    expect($second)->not->toBe($first)
        ->and($disk->exists($second))->toBeTrue()
        ->and($disk->exists($first))->toBeFalse();
});

it('keeps a file another guardian still references', function (): void {
    actingAs(guardianPhotoUser());

    $disk = Storage::disk((string) config('filesystems.default'));
    $action = app(SetGuardianPhoto::class);

    // Identical BYTES: the content hash gives both guardians the same path.
    $bytes = (string) file_get_contents(
        (string) UploadedFile::fake()->image('shared.jpg', 150, 150)->getRealPath()
    );

    $shared = function () use ($bytes): UploadedFile {
        $tmp = tempnam(sys_get_temp_dir(), 'gp').'.jpg';
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'shared.jpg', 'image/jpeg', null, true);
    };

    $one = Guardian::factory()->create();
    $two = Guardian::factory()->create();

    $action->handle($one, $shared());
    $action->handle($two, $shared());

    $path = (string) $one->fresh()?->photo_path;

    expect((string) $two->fresh()?->photo_path)->toBe($path);

    $action->handle($one, UploadedFile::fake()->image('other.jpg', 180, 180));

    expect($disk->exists($path))->toBeTrue()
        ->and((string) $two->fresh()?->photo_path)->toBe($path);
});

it('clears the column and deletes the file when the photograph is removed', function (): void {
    actingAs(guardianPhotoUser());

    $disk = Storage::disk((string) config('filesystems.default'));
    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->set('photoUpload', UploadedFile::fake()->image('face.jpg', 200, 200))
        ->call('savePhoto');

    $path = (string) $guardian->fresh()?->photo_path;

    Livewire::test(Show::class, ['guardian' => $guardian->fresh()])
        ->call('removePhoto')
        ->assertHasNoErrors();

    expect($guardian->fresh()?->photo_path)->toBeNull()
        ->and($disk->exists($path))->toBeFalse();
});

it('refuses a user without guardians.manage', function (): void {
    actingAs(guardianPhotoUser(withPermission: false));

    $guardian = Guardian::factory()->create();

    expect(fn () => app(SetGuardianPhoto::class)->handle(
        $guardian,
        UploadedFile::fake()->image('face.jpg', 200, 200),
    ))->toThrow(AuthorizationException::class);

    expect($guardian->fresh()?->photo_path)->toBeNull();
});
