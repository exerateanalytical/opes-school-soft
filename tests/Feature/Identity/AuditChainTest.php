<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\VerifyAuditChain;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function writeEntry(?User $actor = null, string $action = 'updated'): AuditLog
{
    return app(WriteAuditEntry::class)->handle(
        action: AuditAction::from($action),
        module: 'Identity',
        auditableType: User::class,
        auditableId: 1,
        before: ['name' => 'Old'],
        after: ['name' => 'New'],
        actor: $actor,
    );
}

it('writes an entry with a genesis hash when the log is empty', function () {
    $entry = writeEntry();

    expect($entry->prev_hash)->toBe(str_repeat('0', 64));
    expect($entry->row_hash)->toHaveLength(64);
});

it('chains each entry to its predecessor', function () {
    $first = writeEntry();
    $second = writeEntry();

    expect($second->prev_hash)->toBe($first->row_hash);
});

it('verifies an intact chain', function () {
    writeEntry();
    writeEntry();
    writeEntry();

    $result = app(VerifyAuditChain::class)->handle();

    expect($result->isIntact())->toBeTrue();
    expect($result->checked)->toBe(3);
    expect($result->firstBrokenId)->toBeNull();
});

it('detects a tampered payload', function () {
    writeEntry();
    $target = writeEntry();
    writeEntry();

    // Tamper below the model layer, exactly as an attacker with DB access would.
    DB::table('audit_logs')->where('id', $target->id)->update(['after' => json_encode(['name' => 'Forged'])]);

    $result = app(VerifyAuditChain::class)->handle();

    expect($result->isIntact())->toBeFalse();
    expect($result->firstBrokenId)->toBe($target->id);
});

it('detects a deleted row', function () {
    writeEntry();
    $target = writeEntry();
    writeEntry();

    DB::table('audit_logs')->where('id', $target->id)->delete();

    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeFalse();
});

it('records the actor name at the time so the entry survives a rename', function () {
    $user = User::factory()->create(['name' => 'Original Name']);

    $entry = writeEntry($user);
    expect($entry->actor_name_at_time)->toBe('Original Name');

    $user->update(['name' => 'Changed Name']);

    expect($entry->fresh()?->actor_name_at_time)->toBe('Original Name');
});

it('records a system actor when there is no authenticated user', function () {
    $entry = writeEntry(null);

    expect($entry->actor_id)->toBeNull();
    expect($entry->actor_name_at_time)->toBe('system');
});

it('refuses to update an existing entry', function () {
    $entry = writeEntry();
    $entry->action = 'deleted';
    $entry->save();
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete an entry', function () {
    writeEntry()->delete();
})->throws(RuntimeException::class, 'append-only');
