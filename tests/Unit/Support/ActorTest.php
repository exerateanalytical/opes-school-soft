<?php

declare(strict_types=1);

use App\Support\Audit\Actor;

it('carries an id and the name as it was at the time', function () {
    $actor = new Actor(7, 'Ngwa Bertrand');

    expect($actor->id)->toBe(7);
    expect($actor->name)->toBe('Ngwa Bertrand');
});

it('has a system actor for unattended writes', function () {
    $system = Actor::system();

    expect($system->id)->toBeNull();
    expect($system->name)->toBe('system');
});

it('rejects an empty name, because an unattributable entry is worse than none', function () {
    new Actor(7, '   ');
})->throws(InvalidArgumentException::class);

it('is a pure value object with no framework dependency', function () {
    // It lives in the shared kernel so every module can attribute an audit
    // entry without importing another module's Model (00-core 6.2 vs 14).
    $source = file_get_contents(dirname(__DIR__, 3).'/app/Support/Audit/Actor.php');

    expect($source)->not->toContain('Illuminate\\');
    expect($source)->not->toContain('App\\Modules\\');
});
