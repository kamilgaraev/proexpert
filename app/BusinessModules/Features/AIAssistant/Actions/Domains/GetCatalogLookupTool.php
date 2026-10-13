<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantCatalogLookupReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetCatalogLookupTool implements AIToolInterface
{
    public function __construct(private AssistantCatalogLookupReader $reader) {}

    public function getName(): string
    {
        return 'get_catalog_lookup';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant_operational.catalog_description');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['material_query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'work_type_query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], 'required' => ['material_query', 'work_type_query'], 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id) {
            throw new AccessDeniedHttpException;
        }

        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
