<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RolePermissionNormalizer;
use App\Domain\Authorization\ValueObjects\ModulePermissionAliases;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantMetadataPermissionContractTest extends TestCase
{
    public function test_only_explicit_presale_future_types_are_unavailable_and_never_indexed(): void
    {
        $expected = ['presale_estimate', 'presale_estimate_budget_transfer_operation', 'presale_estimate_line_item',
            'presale_estimate_section', 'presale_estimate_version'];
        $helper = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata::class;
        $availability = \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('availabilityDefinitions');
        $actual = array_keys($availability);
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
        foreach ($expected as $type) {
            self::assertSame('unavailable', $availability[$type]['availability']);
            self::assertSame('no_current_canonical_read_entitlement', $availability[$type]['reason_code']);
            self::assertNotSame('', trim($availability[$type]['reason']));
            self::assertNull($helper::recordDefinitions()[$type]['module']);
            self::assertFalse($helper::retrievalCoverageDefinitions()[$type]['indexed']);
            self::assertSame('unavailable', \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::retrievalMode($type));
        }
        self::assertArrayNotHasKey('presale_estimates', $helper::domainGates());
        self::assertNotContains('presale_estimates', array_map(static fn ($domain): string => $domain->domain, $helper::domainDefinitions()));
        $source = new \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PresaleBusinessRagSource;
        self::assertFalse($source->enabled());
        self::assertSame([], iterator_to_array((static function () use ($source): \Generator { yield from $source->collectForOrganization(1); })()));
        foreach ($expected as $type) { self::assertSame([], $source->collectEntity(1, $type, 1)); }
    }

    public function test_all_finite_business_metadata_permissions_and_modules_have_current_declarations(): void
    {
        $root = dirname(__DIR__,3);
        $knownPermissions = [];
        $modules = [];
        foreach (glob($root.'/config/ModuleList/*/*.json') ?: [] as $path) {
            $module = json_decode((string) file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            $modules[] = $module['slug'];
            foreach ($module['permissions'] ?? [] as $permission) { $knownPermissions[] = is_array($permission) ? $permission['name'] : $permission; }
        }
        foreach (glob($root.'/config/RoleDefinitions/*/*.json') ?: [] as $path) {
            $role = json_decode((string) file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            foreach (RolePermissionNormalizer::normalizeSystemPermissions($role['system_permissions'] ?? [],$role['interface_access'] ?? []) as $permission) {
                if (! str_contains($permission,'*')) { $knownPermissions[] = $permission; }
            }
            foreach (RolePermissionNormalizer::normalizeModulePermissions($role['module_permissions'] ?? []) as $module => $permissions) {
                foreach ($permissions as $permission) {
                    if (! str_contains($permission,'*')) { $knownPermissions[] = str_contains($permission,'.') ? $permission : $module.'.'.$permission; }
                }
            }
        }
        $knownPermissions = array_values(array_unique($knownPermissions));
        $unknown = [];
        $helperCount = 0;
        $permissionCount = 0;
        foreach (\App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog::defaults() as $definition) {
            if ($definition->module !== '' && ! in_array($definition->module,$modules,true)) { $unknown['module:'.$definition->module][] = 'AssistantDomainCatalog.'.$definition->domain; }
            foreach ($this->permissionLeaves([$definition->permissions,$definition->fieldPermissions,$definition->entityPermissions]) as $permission) {
                $permissionCount++;
                if (! $this->knownPermission($permission,$knownPermissions)) { $unknown[$permission][] = 'AssistantDomainCatalog.'.$definition->domain; }
            }
        }
        foreach (glob($root.'/app/BusinessModules/Features/AIAssistant/Services/DomainMetadata/*.php') ?: [] as $path) {
            $helper = 'App\\BusinessModules\\Features\\AIAssistant\\Services\\DomainMetadata\\'.pathinfo($path,PATHINFO_FILENAME);
            if (! method_exists($helper,'entityDefinitions')) { continue; }
            $helperCount++;
            $declarations = [];
            foreach (['entityPermissions','sourcePermissions','fieldPermissions'] as $method) {
                if (method_exists($helper,$method)) { $declarations[$method] = $helper::$method(); }
            }
            if (method_exists($helper,'domainGates')) {
                foreach ($helper::domainGates() as $domain => [$module,$permissions]) {
                    $alternatives = method_exists($helper,'domainModuleAlternatives') ? ($helper::domainModuleAlternatives()[$domain] ?? [$module]) : [$module];
                    if (! in_array('', $alternatives,true) && array_intersect($alternatives,$modules) === []) { $unknown['module:'.$module][] = pathinfo($path,PATHINFO_FILENAME).'.'.$domain; }
                    $declarations['domainGates'][$domain] = $permissions;
                }
            }
            if (method_exists($helper,'domainDefinitions')) {
                foreach ($helper::domainDefinitions() as $definition) {
                    $declarations['domains'][$definition->domain] = [$definition->permissions,$definition->fieldPermissions,$definition->entityPermissions];
                }
            }
            foreach ($declarations as $method => $values) {
                foreach ($values as $key => $value) {
                    foreach ($this->permissionLeaves($value) as $permission) {
                        $permissionCount++;
                        if (! $this->knownPermission($permission,$knownPermissions)) {
                            $unknown[$permission][] = pathinfo($path,PATHINFO_FILENAME).'.'.$method.'.'.$key;
                        }
                    }
                }
            }
        }
        self::assertGreaterThanOrEqual(9,$helperCount);
        self::assertGreaterThan(100,$permissionCount);
        ksort($unknown);
        self::assertSame([],$unknown,json_encode($unknown,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function permissionLeaves(mixed $value): array
    {
        if (is_string($value)) { return [$value]; }
        $result = [];
        foreach (is_array($value) ? $value : [] as $child) { $result = [...$result,...$this->permissionLeaves($child)]; }
        return $result;
    }

    private function knownPermission(string $permission,array $known): bool
    {
        if (in_array($permission,$known,true)) { return true; }
        if (! str_contains($permission,'.') || str_contains($permission,'*')) { return false; }
        [$prefix,$action] = explode('.',$permission,2);
        $reflection = new ReflectionClass(PermissionResolver::class);
        $resolver = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildPermissionVariants');
        foreach (ModulePermissionAliases::variants($prefix) as $module) {
            foreach ($method->invoke($resolver,$prefix,$module,$action) as $variant) {
                if (in_array($variant,$known,true)) { return true; }
            }
        }
        return false;
    }
}
