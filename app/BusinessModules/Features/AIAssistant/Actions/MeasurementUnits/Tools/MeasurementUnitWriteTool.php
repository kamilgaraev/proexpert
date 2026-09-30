<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\ActionResult;
use App\BusinessModules\Features\AIAssistant\Services\WriteAction;
use App\Models\Organization;
use App\Models\User;

abstract class MeasurementUnitWriteTool implements AIToolInterface
{
    abstract protected function action(): WriteAction;

    abstract protected function toolName(): string;

    abstract protected function description(): string;

    abstract protected function schema(): array;

    public function getName(): string
    {
        return $this->toolName();
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function getParametersSchema(): array
    {
        return $this->schema();
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if (! $user instanceof User) {
            return ['status' => 'error', 'message' => trans_message('ai_assistant.action_auth_required')];
        }

        $result = $this->action()->execute($organization->id, $arguments, $user);

        return $this->result($result);
    }

    private function result(ActionResult $result): array
    {
        if (! $result->isSuccess()) {
            return ['status' => 'error', 'message' => $result->getError() ?? trans_message('ai_assistant.action_execute_failed')];
        }

        return [
            'status' => 'success',
            'message' => trans_message('ai_assistant.action_completed'),
            'data' => $result->getData(),
            'metadata' => $result->getMetadata(),
        ];
    }

    protected function unitSchema(bool $requiresId = false): array
    {
        return [
            'type' => 'object',
            'properties' => array_filter([
                'id' => $requiresId ? ['type' => 'integer', 'minimum' => 1] : null,
                'name' => ['type' => 'string', 'maxLength' => 255],
                'short_name' => ['type' => 'string', 'maxLength' => 50],
                'type' => ['type' => 'string', 'enum' => ['material', 'work', 'other']],
                'description' => ['type' => 'string'],
                'is_default' => ['type' => 'boolean'],
            ]),
            'required' => $requiresId ? ['id'] : ['name', 'short_name'],
        ];
    }
}
