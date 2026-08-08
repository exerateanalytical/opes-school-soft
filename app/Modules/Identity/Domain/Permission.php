<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Granular permissions, named module.action.
 *
 * An enum rather than free strings so a typo fails at analysis time instead of
 * silently matching nothing and denying access for reasons nobody can find.
 * 00-core 9.1: every permission is individually grantable on top of the role
 * baseline.
 *
 * This is the Phase 0B set. Later phases ADD cases as their modules land; they
 * must not rename existing ones, because role seeds and granted permissions
 * reference the values.
 */
enum Permission: string
{
    case UserView = 'user.view';
    case UserManage = 'user.manage';
    case UserSetPassword = 'user.set_password';
    case RoleAssign = 'role.assign';
    case PermissionGrant = 'permission.grant';

    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';

    case SettingView = 'setting.view';
    case SettingEdit = 'setting.edit';
    case SettingEditEngine = 'setting.edit_engine';

    case AcademicsView = 'academics.view';
    case AcademicsManage = 'academics.manage';

    case StudentsView = 'students.view';
    case StudentsManage = 'students.manage';

    // Deliberately separate from students.manage (07-students 6.4): finalising
    // a matricule is irreversible, so the right to do it is granted on its own.
    //
    // 07-students spells this `students.matricule.finalise`. The value here is
    // two-segment because every permission in this enum is `module.action` and
    // a test enforces it - not house style for its own sake: these values are
    // also translation keys, and Laravel reads a dot as a nested-array step,
    // which already broke label() once. The right the spec describes is
    // unchanged; only the spelling is.
    case StudentsMatriculeFinalise = 'students.finalise_matricule';

    case GuardiansManage = 'guardians.manage';
    case AdmissionsManage = 'admissions.manage';

    case FeeView = 'fee.view';
    case FeeCollect = 'fee.collect';
    case FeeVoid = 'fee.void';

    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';

    // 02-accounting §3/§5/§6: configuring journals, opening a fiscal year and
    // its accounting periods, and closing/unlocking a period are distinct
    // from posting an entry (ledger.post) - they shape what the ledger IS,
    // not what gets recorded in it. Kept separate so a bursar-adjacent role
    // could in principle post without being able to lock a period out from
    // under everyone else.
    case LedgerConfigure = 'ledger.configure';

    case BackupRun = 'backup.run';
    case BackupRestore = 'backup.restore';
    case LicenceManage = 'licence.manage';

    public function label(string $locale = 'en'): string
    {
        // Permission values contain a dot ('user.view'), and the translator
        // reads dots as nested-array segments - so 'opes.permissions.user.view'
        // would look for ['permissions']['user']['view'] and never find the
        // flat key that lang/*/opes.php actually declares. Fetch the group and
        // index it directly. Missing keys still return the raw key, which is
        // what LocalisationTest asserts against.
        $labels = trans('opes.permissions', [], $locale);

        if (is_array($labels) && is_string($labels[$this->value] ?? null)) {
            return $labels[$this->value];
        }

        return 'opes.permissions.'.$this->value;
    }
}
