<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetBimModelElementsTool implements AIToolInterface
{
    public function __construct(private AssistantBimModelReader $reader) {}

    public function getName(): string
    {
        return 'get_bim_model_elements';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant_bim.tool_description');
    }

    public function getParametersSchema(): array
    {
        $properties = [
            'model_query' => ['type' => ['string', 'null'], 'maxLength' => 200,
                'description' => trans_message('ai_assistant_bim.model_query_description')],
            'element_query' => ['type' => ['string', 'null'], 'maxLength' => 200,
                'description' => trans_message('ai_assistant_bim.element_query_description')],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'version_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AccessDeniedHttpException;
        }

        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
