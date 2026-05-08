<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

final class EnterpriseConstructorSelection
{
    public function __construct(
        public readonly int $users = 100,
        public readonly int $additionalOrganizations = 0,
        public readonly int $extraStorageUnits = 0,
        public readonly bool $extendedAi = false,
        public readonly bool $prioritySupport = false,
        public readonly bool $needsIntegrations = false,
        public readonly bool $needsMigration = false,
        public readonly bool $needsSla = false,
        public readonly bool $moreThan250Users = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            users: (int) ($data['users'] ?? 100),
            additionalOrganizations: (int) ($data['additional_organizations'] ?? 0),
            extraStorageUnits: (int) ($data['extra_storage_units'] ?? 0),
            extendedAi: (bool) ($data['extended_ai'] ?? false),
            prioritySupport: (bool) ($data['priority_support'] ?? false),
            needsIntegrations: (bool) ($data['needs_integrations'] ?? false),
            needsMigration: (bool) ($data['needs_migration'] ?? false),
            needsSla: (bool) ($data['needs_sla'] ?? false),
            moreThan250Users: (bool) ($data['more_than_250_users'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'users' => $this->users,
            'additional_organizations' => $this->additionalOrganizations,
            'extra_storage_units' => $this->extraStorageUnits,
            'extended_ai' => $this->extendedAi,
            'priority_support' => $this->prioritySupport,
            'needs_integrations' => $this->needsIntegrations,
            'needs_migration' => $this->needsMigration,
            'needs_sla' => $this->needsSla,
            'more_than_250_users' => $this->moreThan250Users,
        ];
    }
}
