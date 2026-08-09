<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Who a visitor came to see (phase-10 plan §3 row 10). Staff and Student
 * hosts carry a host_id (a users.id / students.id, validated via DB::table
 * in CheckInVisitor - never a cross-module FK); an Office visit is to a
 * desk, not a person, and carries none.
 */
enum VisitorHostType: string
{
    case Staff = 'staff';
    case Student = 'student';
    case Office = 'office';
}
