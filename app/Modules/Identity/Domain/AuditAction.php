<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordSet = 'password_set';
    case RecoveryGenerated = 'recovery_generated';
    case RecoveryUsed = 'recovery_used';
    case RoleAssigned = 'role_assigned';
    case PermissionGranted = 'permission_granted';
    case SettingChanged = 'setting_changed';

    // 10-documents §19: a clearance/discipline gate override on a Transfer/
    // Leaving/Character certificate is "printed nowhere but logged
    // everywhere" - this is the everywhere.
    case GateOverridden = 'gate_overridden';
}
