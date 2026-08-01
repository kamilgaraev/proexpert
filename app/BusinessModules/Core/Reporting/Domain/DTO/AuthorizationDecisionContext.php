<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeZone;
use InvalidArgumentException;

final readonly class AuthorizationDecisionContext
{
    public array $resources;

    public function __construct(
        public string $channel,
        public int $organizationId,
        public array $holdingOrganizationIds,
        public array $projectIds,
        array $resources,
        public DateTimeZone $timezone,
        public string $correlationId,
        public ?array $transportMetadata,
    ) {
        if (! in_array($channel, ['http', 'queue', 'cli', 'subscription'], true) || $organizationId < 1 || trim($correlationId) === '') {
            throw new InvalidArgumentException('authorization_context_invalid');
        }

        $this->resources = (new ReportScope(
            $organizationId,
            $holdingOrganizationIds,
            $projectIds,
            $resources,
            $timezone,
        ))->resources;
    }

    public function toAuthorizationArray(): array
    {
        return [
            'channel' => $this->channel,
            'organization_id' => $this->organizationId,
            'project_ids' => $this->projectIds,
            'resources' => array_map(
                static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(),
                $this->resources,
            ),
        ];
    }
}
