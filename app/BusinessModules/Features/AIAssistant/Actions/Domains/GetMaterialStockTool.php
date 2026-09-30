<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMaterialStockReader;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetMaterialStockTool implements AIToolInterface
{
    public function __construct(private AssistantMaterialStockReader $reader, private AIPermissionChecker $permissions) {}

    public function getName(): string
    {
        return 'get_material_stock';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant.material_stock_description');
    }

    public function getParametersSchema(): array
    {
        $properties = [
            'query' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'material_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 100],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => trans_message('ai_assistant.material_stock_project_scope_note')],
            'warehouse_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id) {
            throw new AccessDeniedHttpException;
        }
        Validator::make($arguments, [
            'query' => ['nullable', 'string', 'max:200'],
            'material_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'material_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        if (array_diff(array_keys($arguments), array_keys($this->getParametersSchema()['properties'])) !== []) {
            throw new \InvalidArgumentException('unsupported_material_stock_argument');
        }
        if (! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new AccessDeniedHttpException;
        }

        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
