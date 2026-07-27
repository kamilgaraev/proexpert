<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeZone;
use InvalidArgumentException;

final readonly class AuthorizationDecisionContext
{
    public function __construct(
        public string $channel,
        public int $organizationId,
        public array $holdingOrganizationIds,
        public array $projectIds,
        public array $resourceIds,
        public DateTimeZone $timezone,
        public string $correlationId,
        public ?array $transportMetadata,
    ) {
        if (!in_array($channel, ['http', 'queue', 'cli', 'subscription'], true) || $organizationId < 1 || trim($correlationId) === '') {
            throw new InvalidArgumentException('authorization_context_invalid');
        }
    }

    public function toAuthorizationArray(): array
    {
        return [
            'channel' => $this->channel,
            'organization_id' => $this->organizationId,
            'project_ids' => $this->projectIds,
            'resource_ids' => $this->resourceIds,
        ];
    }
}
