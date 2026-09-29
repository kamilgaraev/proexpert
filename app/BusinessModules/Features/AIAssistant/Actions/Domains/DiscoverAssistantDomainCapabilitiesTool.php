<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class DiscoverAssistantDomainCapabilitiesTool implements AIToolInterface
{
    public function __construct(private AssistantDomainCatalog $catalog, private AssistantDataAccessPolicy $access, private AuthorizationService $authorization) {}

    public function getName(): string
    {
        return 'assistant_domain_discover_capabilities';
    }

    public function getDescription(): string
    {
        return 'Постраничный конечный каталог зарегистрированных типов и разрешённых полей МОСТ для текущих прав и модулей. Не читает записи и не подтверждает их наличие. Для чтения используй точный domain/entity_type; fields=null выбирает доступные поля.';
    }

    public function getParametersSchema(): array
    {
        $properties = [
            'domain' => ['type' => ['string', 'null'], 'maxLength' => 128],
            'entity_type' => ['type' => ['string', 'null'], 'maxLength' => 128],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'field_offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
            'field_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 16],
        ];
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || ! $this->access->canReadDomain($user, (int) $organization->id, 'assistant')) {
            throw new AccessDeniedHttpException;
        }
        return $this->describe(
            $arguments,
            fn (string $domain): bool => $this->access->canReadDomain($user, (int) $organization->id, $domain),
            fn (string $permission): bool => $this->authorization->canCurrent($user, $permission, ['organization_id' => (int) $organization->id]),
            fn (string $type): bool => $this->access->entityQuery($user, (int) $organization->id, $type) !== null,
        );
    }

    public function describe(array $arguments, callable $domainAllowed, callable $permissionAllowed, callable $entityAllowed): array
    {
        $schema = $this->getParametersSchema();
        if (array_diff(array_keys($arguments), $schema['required']) !== []) {
            throw new InvalidArgumentException('unsupported_capability_argument');
        }
        $arguments += ['domain' => null, 'entity_type' => null, 'offset' => 0, 'limit' => 3, 'field_offset' => 0, 'field_limit' => 16];
        foreach (['domain', 'entity_type'] as $key) {
            if ($arguments[$key] !== null && (! is_string($arguments[$key]) || strlen($arguments[$key]) > 128 || $arguments[$key] === '')) {
                throw new InvalidArgumentException('invalid_capability_filter');
            }
        }
        foreach (['offset', 'limit', 'field_offset', 'field_limit'] as $key) {
            if (! is_int($arguments[$key]) || $arguments[$key] < $schema['properties'][$key]['minimum'] || $arguments[$key] > $schema['properties'][$key]['maximum']) {
                throw new InvalidArgumentException('invalid_capability_page');
            }
        }
        $allowed = static function (array $permissions) use ($permissionAllowed): bool {
            foreach ($permissions as $permission) {
                if (! $permissionAllowed($permission)) {
                    return false;
                }
            }
            return true;
        };
        $candidates = [];
        foreach ($this->catalog->all() as $definition) {
            if (($arguments['domain'] !== null && $arguments['domain'] !== $definition->domain)
                || ! $domainAllowed(match ($definition->domain) { 'works' => 'projects', 'acts' => 'contracts', default => $definition->domain })
                || ! $allowed($definition->permissions)) {
                continue;
            }
            foreach ($definition->entityTypes as $type) {
                if (($arguments['entity_type'] !== null && $arguments['entity_type'] !== $type)
                    || ! $allowed((array) ($definition->entityPermissions[$type] ?? []))) {
                    continue;
                }
                $candidates[] = [$definition, $type];
            }
        }
        $rows = [];
        $next = $arguments['offset'];
        foreach (array_slice($candidates, $next, 12) as [$definition, $type]) {
            $next++;
            if ($entityAllowed($type)) {
                $rows[] = $this->row($definition, $type, $arguments, $allowed);
            }
            if (count($rows) === $arguments['limit']) {
                break;
            }
        }
        return ['capabilities' => $rows, 'next_offset' => $next < count($candidates) ? $next : null, 'record_access' => 'checked_on_read'];
    }

    private function row(AssistantDomainDefinition $definition, string $type, array $arguments, callable $allowed): array
    {
        $fields = array_values(array_filter($definition->fields, fn (string $field): bool => $allowed((array) ($definition->fieldPermissions[$field] ?? []))));
        $next = $arguments['field_offset'] + $arguments['field_limit'];
        $projections = [];
        foreach (\App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('parentProjectionDefinitions')[$type] ?? [] as $name => $projection) {
            $projections[] = ['projection_name' => $name, 'status' => 'live_read', 'parent_identifier' => 'entity_id',
                'pagination' => ['offset_argument' => 'projection_offset', 'limit_argument' => 'projection_limit', 'max_limit' => 20]];
        }
        return ['domain' => $definition->domain, 'entity_type' => $type, 'operations' => $definition->operations,
            'fields' => array_slice($fields, $arguments['field_offset'], $arguments['field_limit']), 'field_count' => count($fields),
            'next_field_offset' => $next < count($fields) ? $next : null, 'projections' => $projections];
    }
}
