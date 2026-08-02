<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use InvalidArgumentException;

final readonly class CustomerActorSideResolver
{
    public function resolve(
        int $ownerOrganizationId,
        ?int $customerOrganizationId,
        array $actorOrganizationIds,
    ): CustomerActorSide {
        if (
            $ownerOrganizationId < 1
            || ($customerOrganizationId !== null && $customerOrganizationId < 1)
            || !array_is_list($actorOrganizationIds)
        ) {
            throw new InvalidArgumentException('customer_actor_side_context_invalid');
        }

        $memberships = [];
        foreach ($actorOrganizationIds as $organizationId) {
            if (!is_int($organizationId) || $organizationId < 1) {
                throw new InvalidArgumentException('customer_actor_side_context_invalid');
            }
            $memberships[$organizationId] = true;
        }

        if ($customerOrganizationId === null || $customerOrganizationId === $ownerOrganizationId) {
            return CustomerActorSide::UNKNOWN;
        }

        $isCustomer = isset($memberships[$customerOrganizationId]);
        $isDeliveryTeam = isset($memberships[$ownerOrganizationId]);

        return match (true) {
            $isCustomer && !$isDeliveryTeam => CustomerActorSide::CUSTOMER,
            $isDeliveryTeam && !$isCustomer => CustomerActorSide::DELIVERY_TEAM,
            default => CustomerActorSide::UNKNOWN,
        };
    }
}
