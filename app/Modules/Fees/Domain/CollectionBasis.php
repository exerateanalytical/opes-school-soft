<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.2, C5. NOT NULL with no default in the schema: the developer
 * must decide whether money billed under an item belongs to the school
 * (class 7 revenue) or is held for a third party (class 47 liability).
 */
enum CollectionBasis: string
{
    case OwnRevenue = 'own_revenue';
    case AgentForThirdParty = 'agent_for_third_party';
}
