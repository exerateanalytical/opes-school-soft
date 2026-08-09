<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * What kind of work a vehicle_maintenance_logs row records. Operational
 * taxonomy only - the money side of the work lives on the Phase 5 supplier
 * invoice, never here.
 */
enum VehicleMaintenanceType: string
{
    case Service = 'service';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Other = 'other';
}
