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

    case FeeView = 'fee.view';
    case FeeCollect = 'fee.collect';
    case FeeVoid = 'fee.void';

    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';

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
