<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\ReportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportTemplateControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_organization_and_system_templates_with_safe_filters_and_sorting(): void
    {
        $this->allowReportTemplatePermissions();
        $context = AdminApiTestContext::create();

        $systemTemplate = $this->createTemplate(null, [
            'name' => 'System contractor summary',
            'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
            'is_default' => true,
        ]);
        $organizationTemplate = $this->createTemplate($context->organization->id, [
            'name' => 'Organization contractor summary',
            'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
        ]);
        $foreignTemplate = $this->createTemplate(Organization::factory()->verified()->create()->id, [
            'name' => 'Foreign contractor summary',
            'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
        ]);
        $this->createTemplate($context->organization->id, [
            'name' => 'Own material template',
            'report_type' => ReportTemplate::REPORT_TYPE_MATERIAL_USAGE,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-templates?' . http_build_query([
                'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
                'name' => 'summary',
                'sort_by' => 'created_at desc',
                'sort_direction' => 'sideways',
                'per_page' => 0,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($systemTemplate->id, $ids);
        $this->assertContains($organizationTemplate->id, $ids);
        $this->assertNotContains($foreignTemplate->id, $ids);
        $this->assertSame(2, count($ids));
    }

    public function test_template_lifecycle_is_scoped_to_current_organization_and_keeps_single_default_per_type(): void
    {
        $this->allowReportTemplatePermissions();
        $context = AdminApiTestContext::create();
        $foreignTemplate = $this->createTemplate(Organization::factory()->verified()->create()->id);
        $existingDefault = $this->createTemplate($context->organization->id, [
            'name' => 'Old default',
            'is_default' => true,
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/report-templates', [
                'name' => 'New default',
                'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
                'is_default' => true,
                'columns_config' => [
                    [
                        'header' => 'Contractor',
                        'data_key' => 'contractor_name',
                        'order' => 1,
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.name', 'New default');
        $createResponse->assertJsonPath('data.is_default', true);

        $createdId = $createResponse->json('data.id');
        $this->assertDatabaseHas('report_templates', [
            'id' => $existingDefault->id,
            'is_default' => false,
        ]);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-templates/' . $createdId);

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $createdId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/report-templates/' . $createdId, [
                'name' => 'Updated default',
                'columns_config' => [
                    [
                        'header' => 'Amount',
                        'data_key' => 'total_amount',
                        'order' => 1,
                    ],
                ],
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Updated default');
        $updateResponse->assertJsonPath('data.columns_config.0.data_key', 'total_amount');

        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/report-templates/' . $foreignTemplate->id, [
                'name' => 'Should not update',
            ]);

        $foreignUpdateResponse->assertNotFound();

        $systemTemplate = $this->createTemplate(null, [
            'name' => 'Readonly system template',
        ]);

        $systemUpdateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/report-templates/' . $systemTemplate->id, [
                'name' => 'Should not update system template',
            ]);

        $systemUpdateResponse->assertNotFound();
        $this->assertDatabaseHas('report_templates', [
            'id' => $systemTemplate->id,
            'name' => 'Readonly system template',
        ]);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/report-templates/' . $createdId);

        $deleteResponse->assertOk();
        $this->assertSoftDeleted('report_templates', ['id' => $createdId]);
    }

    public function test_template_validation_returns_admin_contract_without_creating_record(): void
    {
        $this->allowReportTemplatePermissions();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/report-templates', [
                'name' => 'Invalid template',
                'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
                'columns_config' => [
                    [
                        'header' => 'Contractor',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'columns_config.0.data_key',
                'columns_config.0.order',
            ],
        ]);
        $this->assertDatabaseCount('report_templates', 0);
    }

    private function allowReportTemplatePermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function createTemplate(?int $organizationId, array $attributes = []): ReportTemplate
    {
        return ReportTemplate::query()->create(array_merge([
            'name' => 'Report template',
            'report_type' => ReportTemplate::REPORT_TYPE_CONTRACTOR_SUMMARY,
            'organization_id' => $organizationId,
            'user_id' => null,
            'columns_config' => [
                [
                    'header' => 'Contractor',
                    'data_key' => 'contractor_name',
                    'order' => 1,
                ],
            ],
            'is_default' => false,
        ], $attributes));
    }
}
