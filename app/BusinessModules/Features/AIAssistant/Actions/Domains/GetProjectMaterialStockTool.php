<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectMaterialStockReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetProjectMaterialStockTool implements AIToolInterface
{
    public function __construct(private AssistantProjectMaterialStockReader $reader, private AIPermissionChecker $permissions) {}
    public function getName(): string { return 'get_project_material_stock'; }
    public function getDescription(): string { return trans_message('ai_assistant.project_material_stock_description'); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]],
            'required' => ['project_id', 'offset', 'limit'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
