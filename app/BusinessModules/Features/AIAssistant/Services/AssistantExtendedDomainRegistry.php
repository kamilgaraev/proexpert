<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantExtendedDomainRegistry
{
    private static array $definitions = [];

    private const HELPERS = [
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantWorkforceCatalogMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\VideoMonitoringAssistantMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantDesignAdditionalMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOperationsBusinessMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantLegalBusinessMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOrganizationReportingMetadata::class,
        \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata::class,
    ];

    public static function values(string $method): array
    {
        if (array_key_exists($method, self::$definitions)) { return self::$definitions[$method]; }
        $values = [];
        foreach (self::HELPERS as $helper) {
            if ($method === 'structuredFields' && class_exists($helper) && method_exists($helper, 'domainDefinitions')) {
                foreach ($helper::domainDefinitions() as $definition) { $values = array_merge($values, $definition->fields); }
            }
            if (class_exists($helper) && method_exists($helper, $method)) {
                foreach ($helper::$method() as $key => $value) {
                    if (is_int($key)) { $values[] = $value; }
                    elseif ($method === 'factFieldGroups') { $values[$key] = array_values(array_unique(array_merge($values[$key] ?? [], $value))); }
                    else { $values[$key] = $value; }
                }
            }
        }

        return self::$definitions[$method] = $values;
    }

    public static function applyActorScopes(string $type, \Illuminate\Database\Eloquent\Builder $query, \App\Models\User $actor,
        int $organizationId, \App\Domain\Authorization\Services\AuthorizationService $authorization, AssistantDataAccessPolicy $policy): bool
    {
        foreach (self::HELPERS as $helper) {
            if (class_exists($helper) && method_exists($helper, 'entityDefinitions') && isset($helper::entityDefinitions()[$type])
                && method_exists($helper, 'applyActorScope')) {
                try {
                    $helper::applyActorScope($type, $query, $actor, $organizationId, $authorization, $policy);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function retrievalMode(string $type): string
    {
        if ((self::values('availabilityDefinitions')[$type]['availability'] ?? 'available') !== 'available') { return 'unavailable'; }
        $mode = self::values('retrievalCoverageDefinitions')[$type]['mode'] ?? 'indexed_private';
        if (! in_array($mode, ['live_only', 'indexed_private', 'indexed_global', 'unavailable'], true)) { return 'unavailable'; }

        return $mode;
    }

    public static function applyCustomOrganizationScope(string $type, \Illuminate\Database\Eloquent\Builder $query, \App\Models\User $actor,
        int $organizationId, \App\Domain\Authorization\Services\AuthorizationService $authorization, AssistantDataAccessPolicy $policy): bool
    {
        foreach (self::HELPERS as $helper) {
            if (! class_exists($helper) || ! method_exists($helper, 'customOrganizationScopes')
                || (($helper::customOrganizationScopes()[$type] ?? false) !== true)) { continue; }
            if (! method_exists($helper, 'entityDefinitions') || ! isset($helper::entityDefinitions()[$type])
                || ! method_exists($helper, 'applyOrganizationScope')) { return false; }
            try {
                return $helper::applyOrganizationScope($type, $query, $actor, $organizationId, $authorization, $policy) === true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
