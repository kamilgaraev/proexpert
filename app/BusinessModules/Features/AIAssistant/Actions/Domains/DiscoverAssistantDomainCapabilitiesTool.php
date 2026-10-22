<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadToolCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class DiscoverAssistantDomainCapabilitiesTool implements AIToolInterface
{
    public function __construct(
        private AssistantDomainCatalog $catalog,
        private AssistantDataAccessPolicy $access,
        private AIToolRegistry $tools,
        private AIPermissionChecker $permissions,
        private AssistantToolEligibilityPolicy $eligibility,
    ) {}

    public function getName(): string
    {
        return 'assistant_domain_discover_capabilities';
    }

    public function getDescription(): string
    {
        if (! AssistantReadToolCatalog::enabled()) {
            return 'Постраничный конечный каталог зарегистрированных типов и разрешённых полей МОСТ для текущих прав и модулей. Не читает записи и не подтверждает их наличие. Для чтения используй точный domain/entity_type; fields=null выбирает доступные поля.';
        }
        return 'Каталог разрешённых типов, полей и инструментов чтения. Не читает записи и не подтверждает их наличие. read_tool_offset/read_tool_limit листают инструменты; read_tool_names выбирает до 3 инструментов для следующих вызовов, независимо от формулировки вопроса. Выбор заменяет прежний дополнительный набор; null сохраняет его. Права и бюджет проверяет сервер. Для данных используй точный domain/entity_type; fields=null выбирает доступные поля.';
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
        if (AssistantReadToolCatalog::enabled()) {
            $properties += [
                'read_tool_offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
                'read_tool_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => AssistantReadToolCatalog::MAX_PAGE],
                'read_tool_names' => ['type' => ['array', 'null'], 'maxItems' => AssistantReadToolCatalog::MAX_SELECTED,
                    'items' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-zA-Z_][a-zA-Z0-9_]{0,63}$']],
            ];
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) { throw new AccessDeniedHttpException; }
        return $this->access->withCurrentChecks($user, (int) $organization->id,
            fn (): array => $this->executeCurrent($arguments, $user, $organization), true);
    }

    public function compactForActor(User $actor, int $organizationId, bool $fresh = true): array
    {
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId): array {
            if (!$this->access->canReadDomain($actor, $organizationId, 'assistant')) {
                throw new AccessDeniedHttpException;
            }
            $catalog = $this->compact(
                fn (string $domain): bool => $this->access->canReadDomain($actor, $organizationId, $domain),
                fn (string $permission): bool => $this->access->canCurrentPermission($actor, $organizationId, $permission),
            );
            if (AssistantReadToolCatalog::enabled()) {
                $catalog['read_tool_discovery'] += (new AssistantReadToolCatalog($this->tools, $this->eligibility))->index(
                    fn (string $name): bool => $this->canDescribeReadTool($actor, $name));
            }

            return $catalog;
        }, $fresh);
    }

    public function compact(callable $domainAllowed, callable $permissionAllowed): array
    {
        $rows = [];
        $domainDecisions = [];
        foreach ($this->catalog->all() as $definition) {
            $domain = match ($definition->domain) { 'works' => 'projects', 'acts' => 'contracts', default => $definition->domain };
            $domainDecisions[$domain] ??= $domainAllowed($domain);
            if (!$domainDecisions[$domain]) {
                continue;
            }
            foreach ($definition->permissions as $permission) {
                if (!$permissionAllowed($permission)) {
                    continue 2;
                }
            }
            $allowedTypes = [];
            foreach ($definition->entityTypes as $type) {
                foreach ((array) ($definition->entityPermissions[$type] ?? []) as $permission) {
                    if (!$permissionAllowed($permission)) {
                        continue 2;
                    }
                }
                $allowedTypes[] = $type;
            }
            if ($allowedTypes !== []) {
                $rows[] = ['domain' => $definition->domain, 'primary_entity_type' => $allowedTypes[0],
                    'label' => trans_message('ai_assistant.capability_'.$definition->domain),
                    'entity_examples' => array_slice($allowedTypes, 0, 2),
                    'entity_type_count' => count($allowedTypes), 'operations' => $definition->operations];
            }
        }
        $catalog = ['domains' => $rows, 'details_tool' => $this->getName(), 'record_access' => 'checked_on_read'];
        if (AssistantReadToolCatalog::enabled()) {
            $catalog['read_tool_discovery'] = ['tool' => $this->getName(), 'selection_argument' => 'read_tool_names',
                'maximum_selected' => AssistantReadToolCatalog::MAX_SELECTED, 'catalog_offset_argument' => 'read_tool_offset'];
        }
        return $catalog;
    }

    private function executeCurrent(array $arguments, User $user, Organization $organization): array
    {
        if (! $this->access->canReadDomain($user, (int) $organization->id, 'assistant')) {
            throw new AccessDeniedHttpException;
        }
        $result = $this->describe(
            $arguments,
            fn (string $domain): bool => $this->access->canReadDomain($user, (int) $organization->id, $domain),
            fn (string $permission): bool => $this->access->canCurrentPermission($user, (int) $organization->id, $permission),
            fn (string $type): bool => $this->access->entityQuery($user, (int) $organization->id, $type) !== null,
        );
        if (AssistantReadToolCatalog::enabled()) {
            $result += (new AssistantReadToolCatalog($this->tools, $this->eligibility))->describe($arguments,
                fn (string $name): bool => $this->canDescribeReadTool($user, $name));
        }
        return $result;
    }

    private function canDescribeReadTool(User $actor, string $name): bool
    {
        $execution = app()->bound(AssistantRequestExecutionContext::class) ? app(AssistantRequestExecutionContext::class) : null;
        $execution?->remainingMilliseconds();
        $allowed = $this->permissions->canExposeTool($actor, $name, false);
        $execution?->remainingMilliseconds();

        return $allowed;
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
