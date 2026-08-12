<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\AuxiliaryControlChecks;
use App\Modules\Accounting\Domain\ControlStatus;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function auxiliaryControlUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('reports every collective account as reconciled on a consistent ledger', function () {
    actingAs(auxiliaryControlUser());

    $checks = app(AuxiliaryControlChecks::class)->handle();

    foreach ($checks as $check) {
        expect($check->status)->toBe(ControlStatus::Reconciled);
        expect($check->difference)->toBe(0);
    }
});

it('states an axis and an as_of on every check', function () {
    actingAs(auxiliaryControlUser());

    $checks = app(AuxiliaryControlChecks::class)->handle();

    foreach ($checks as $check) {
        expect($check->asOf)->not->toBeEmpty();
        expect($check->axis)->toBeIn(['fiscal_year', 'academic_year']);
    }
});

it('refuses without ledger.view', function () {
    actingAs(auxiliaryControlUser(Role::Teacher));

    app(AuxiliaryControlChecks::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('reports a difference when an auxiliary line loses its partner', function () {
    actingAs(auxiliaryControlUser());

    $collective = ChartOfAccount::query()
        ->where('is_collective', true)
        ->where('is_archived', false)
        ->first();

    if ($collective === null) {
        $this->markTestSkipped('no collective account seeded');

        return;
    }

    $line = JournalEntryLine::query()
        ->where('account_id', $collective->id)
        ->whereNotNull('partner_id')
        ->first();

    if ($line === null) {
        $this->markTestSkipped('no auxiliary line seeded on a collective account');

        return;
    }

    // L8 (database/migrations/2026_08_07_230010_add_auxiliary_columns_to_
    // journal_entry_lines.php) has no session-variable bypass - it is a
    // plain BEFORE UPDATE trigger with an unconditional SIGNAL. There is no
    // "opt out for this statement" hook. To simulate the integrity fault the
    // control is designed to catch, the trigger is dropped for the duration
    // of one UPDATE and recreated verbatim (same SQL as the migration's up())
    // in a finally block, so the schema is never left without L8 protection.
    DB::unprepared('DROP TRIGGER IF EXISTS trg_jel_l8_before_update');

    try {
        DB::table('journal_entry_lines')->where('id', $line->id)->update(['partner_id' => null]);
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_jel_l8_before_update
            BEFORE UPDATE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_is_collective TINYINT(1);
                DECLARE v_allowed JSON;

                SELECT is_collective, allowed_partner_types INTO v_is_collective, v_allowed
                FROM chart_of_accounts WHERE id = NEW.account_id;

                IF v_is_collective = 1 AND (NEW.partner_type IS NULL OR NEW.partner_id IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: collective account requires a partner';
                END IF;

                IF v_is_collective = 0 AND NEW.partner_type IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: non-collective account must not carry a partner';
                END IF;

                IF v_is_collective = 1 AND v_allowed IS NOT NULL AND NEW.partner_type IS NOT NULL
                   AND NOT JSON_CONTAINS(v_allowed, JSON_QUOTE(NEW.partner_type)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: partner_type is not in the account allowed_partner_types';
                END IF;
            END
        SQL);
    }

    $checks = app(AuxiliaryControlChecks::class)->handle();
    $broken = $checks->firstWhere('key', 'auxiliary_'.$collective->code);

    expect($broken->status)->toBe(ControlStatus::Difference);
    expect($broken->difference)->not->toBe(0);
});
