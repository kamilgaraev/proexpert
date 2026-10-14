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
            'answer_kind' => ['type' => 'string', 'enum' => ['elements', 'materials', 'models'],
                'description' => trans_message('ai_assistant_bim.answer_kind_description')],
            'model_query' => ['type' => ['string', 'null'], 'maxLength' => 200,
                'description' => trans_message('ai_assistant_bim.model_query_description')],
            'element_query' => ['type' => ['string', 'null'], 'maxLength' => 200,
                'description' => trans_message('ai_assistant_bim.element_query_description')],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'version_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'compare_version_id' => ['type' => ['integer', 'null'], 'minimum' => 1,
                'description' => trans_message('ai_assistant_bim.compare_version_description')],
            'property_name' => ['type' => ['string', 'null'], 'maxLength' => 100,
                'description' => trans_message('ai_assistant_bim.property_description')],
            'property_value' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'material_query' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'classification_query' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'storey_query' => ['type' => ['string', 'null'], 'maxLength' => 200],
            'equipment_kind' => ['type' => ['string', 'null'], 'enum' => ['air_conditioner', 'fan', 'pump', 'boiler', 'chiller', 'unknown', null]],
            'quantity_name' => ['type' => ['string', 'null'], 'maxLength' => 100],
            'include_quantities' => ['type' => 'boolean', 'description' => trans_message('ai_assistant_bim.quantities_description')],
            'include_properties' => ['type' => 'boolean', 'description' => trans_message('ai_assistant_bim.properties_description')],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'include_estimate_links' => ['type' => 'boolean',
                'description' => trans_message('ai_assistant_bim.estimate_links_description')],
        ];

        $required = array_keys($properties);
        $properties['equipment_kinds'] = ['type' => ['array', 'null'], 'minItems' => 1, 'maxItems' => 6,
            'items' => ['type' => 'string', 'enum' => ['air_conditioner', 'fan', 'pump', 'boiler', 'chiller', 'ventilation_unit']]];
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AccessDeniedHttpException;
        }

        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
