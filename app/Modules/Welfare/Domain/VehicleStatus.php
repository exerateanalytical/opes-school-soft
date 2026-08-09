<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Operational state of a fleet vehicle - the three bands the Transport
 * dashboard's "Vehicle Status" rail counts. Distinct from the Phase 9
 * asset-register lifecycle: an asset can be fully depreciated yet
 * operational, and a brand-new bus can be out of service awaiting plates.
 */
enum VehicleStatus: string
{
    case Operational = 'operational';
    case UnderMaintenance = 'under_maintenance';
    case OutOfService = 'out_of_service';
}
