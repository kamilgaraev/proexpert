<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class AssistantDomainTool implements AIToolInterface
{
    public function __construct(private readonly AssistantDomainCatalog $catalog, private readonly AssistantDomainReadService $reader) {}

    abstract protected function operation(): string;

    public function getName(): string
    {
        return 'assistant_domain_'.$this->operation();
    }

    public function getDescription(): string
    {
        return match ($this->operation()) {
            'search' => 'Поиск доступных сущностей МОСТ по текущим данным. Возвращает проверенные идентификаторы и ссылки на источники.',
            'read' => 'Чтение текущих полей доступной сущности МОСТ по проверенному идентификатору.',
            default => 'Получение ссылки на доступную сущность МОСТ по проверенному идентификатору.',
        };
    }

    public function getParametersSchema(): array
    {
        $definitions = $this->catalog->all();
        $types = [];
        $fields = [];
        foreach ($definitions as $definition) {
            array_push($types, ...$definition->entityTypes);
            array_push($fields, ...$definition->fields);
        }
        $properties = [
            'domain' => ['type' => 'string', 'enum' => array_keys($definitions)],
            'entity_type' => ['type' => 'string', 'enum' => array_values(array_unique($types))],
        ];
        if ($this->operation() === 'search') {
            $properties += ['query' => ['type' => 'string', 'maxLength' => 200, 'description' => trans_message('ai_assistant_search.query_description')], 'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]];
        } else {
            $properties['id'] = ['type' => ['integer', 'string'], 'minimum' => 1, 'minLength' => 1, 'maxLength' => 36,
                'pattern' => '^(?:[1-9][0-9]*|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-7][0-9A-HJKMNP-TV-Z]{25})$'];
        }
        if ($this->operation() !== 'navigation') {
            $properties['fields'] = ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => array_values(array_unique($fields))]];
        }
        if ($this->operation() === 'read') {
            $properties += ['projection' => ['type' => ['string', 'null'], 'maxLength' => 128],
                'projection_offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
                'projection_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]];
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AccessDeniedHttpException();
        }
        return $this->reader->execute($this->operation(), $arguments, $user, (int) $organization->id);
    }
}
