<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EstimateGenerationRbacTest extends TestCase
{
    #[DataProvider('protectedActions')]
    public function test_every_estimate_generation_endpoint_has_its_domain_permission(
        string $method,
        string $uri,
        string $permission,
    ): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3)
            .'/app/BusinessModules/Addons/EstimateGeneration/routes.php');
        $routePath = str_replace('api/v1/admin/projects/{project}/estimate-generation/sessions', '', $uri);
        $routePath = str_replace('api/v1/admin/estimate-generation/normatives', '', $routePath);
        $routePath = $routePath === '' ? '/' : $routePath;
        $verb = strtolower($method) === 'get' ? 'get' : 'post';
        $pattern = sprintf(
            "/Route::%s\\('%s'.*?->middleware\\('authorize:%s'\\)/",
            $verb,
            preg_quote($routePath, '/'),
            preg_quote($permission, '/'),
        );

        $this->assertMatchesRegularExpression($pattern, $source, "{$method} {$uri}");
        $this->assertStringNotContainsString("'authorize:admin.access'", $source);
    }

    public static function protectedActions(): array
    {
        $prefix = 'api/v1/admin/projects/{project}/estimate-generation/sessions';

        return [
            'sessions index' => ['GET', "{$prefix}", 'estimate_generation.view'],
            'sessions create' => ['POST', "{$prefix}", 'estimate_generation.create'],
            'documents index' => ['GET', "{$prefix}/{session}/documents", 'estimate_generation.view'],
            'documents upload' => ['POST', "{$prefix}/{session}/documents", 'estimate_generation.upload_documents'],
            'document show' => ['GET', "{$prefix}/{session}/documents/{document}", 'estimate_generation.view'],
            'document retry' => ['POST', "{$prefix}/{session}/documents/{document}/retry", 'estimate_generation.review'],
            'document ignore' => ['POST', "{$prefix}/{session}/documents/{document}/ignore", 'estimate_generation.review'],
            'analyze' => ['POST', "{$prefix}/{session}/analyze", 'estimate_generation.generate'],
            'generate' => ['POST', "{$prefix}/{session}/generate", 'estimate_generation.generate'],
            'status' => ['GET', "{$prefix}/{session}/status", 'estimate_generation.view'],
            'packages index' => ['GET', "{$prefix}/{session}/packages", 'estimate_generation.view'],
            'package show' => ['GET', "{$prefix}/{session}/packages/{package}", 'estimate_generation.view'],
            'draft' => ['GET', "{$prefix}/{session}/draft", 'estimate_generation.view'],
            'review items' => ['GET', "{$prefix}/{session}/review-items", 'estimate_generation.view'],
            'session show' => ['GET', "{$prefix}/{session}", 'estimate_generation.view'],
            'export' => ['GET', "{$prefix}/{session}/export", 'estimate_generation.export'],
            'apply' => ['POST', "{$prefix}/{session}/apply", 'estimate_generation.apply'],
            'candidate search' => ['GET', "{$prefix}/{session}/normative-candidates/search", 'estimate_generation.select_normative'],
            'candidate select' => ['POST', "{$prefix}/{session}/normative-candidate", 'estimate_generation.select_normative'],
            'rebuild section' => ['POST', "{$prefix}/{session}/rebuild-section", 'estimate_generation.generate'],
            'feedback' => ['POST', "{$prefix}/{session}/feedback", 'estimate_generation.review'],
            'normative statuses' => ['GET', 'api/v1/admin/estimate-generation/normatives/statuses', 'estimate_generation.select_normative'],
        ];
    }

    public function test_estimate_generation_roles_are_explicit_and_viewer_roles_cannot_apply(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'RoleDefinitions';
        $organizationAdmin = json_decode((string) file_get_contents($root.'/lk/organization_admin.json'), true, 512, JSON_THROW_ON_ERROR);
        $projectAdmin = json_decode((string) file_get_contents($root.'/project/parent_administrator.json'), true, 512, JSON_THROW_ON_ERROR);

        $expected = [
            'estimate_generation.view',
            'estimate_generation.create',
            'estimate_generation.upload_documents',
            'estimate_generation.generate',
            'estimate_generation.review',
            'estimate_generation.select_normative',
            'estimate_generation.export',
            'estimate_generation.apply',
        ];

        $this->assertSame($expected, $organizationAdmin['module_permissions']['estimate-generation']);
        $this->assertSame($expected, $projectAdmin['module_permissions']['estimate-generation']);

        foreach (glob($root.'/*/*.json') ?: [] as $roleFile) {
            if (! str_contains(basename($roleFile), 'viewer') && ! str_contains(basename($roleFile), 'observer')) {
                continue;
            }

            $role = json_decode((string) file_get_contents($roleFile), true, 512, JSON_THROW_ON_ERROR);
            $permissions = array_merge($role['system_permissions'] ?? [], ...array_values($role['module_permissions'] ?? []));
            $this->assertNotContains('estimate_generation.apply', $permissions, $roleFile);
        }
    }
}
