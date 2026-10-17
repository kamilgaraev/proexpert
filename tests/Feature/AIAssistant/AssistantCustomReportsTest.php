<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantCustomReportsTest extends TestCase
{
    private AssistantCustomReportBuilder $builder;

    private AssistantCustomReportSource $sources;

    private AssistantCustomReportReference $references;

    private AssistantDataAccessPolicy $policy;

    private array $denied = [];

    private array $extraModules = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => ! in_array($permission, $this->denied, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModuleSlugs')->andReturnUsing(fn (int $organizationId): array => $entitlements->getEffectiveModules($organizationId)->pluck('slug')->all());
        $entitlements->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug],
            ['project-management', 'contract-management', 'payments', 'reports', 'ai-assistant', ...$this->extraModules])));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $entitlements);
        $this->sources = new AssistantCustomReportSource(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->policy);
        $this->references = new AssistantCustomReportReference($this->sources, $this->policy);
        $this->builder = new AssistantCustomReportBuilder($this->sources, $this->references, $this->policy);
        app()->instance(AssistantCustomReportReference::class, $this->references);
    }

    public function test_detail_uses_current_organization_and_assigned_project_acl(): void
    {
        [$org, $actor, $visible] = $this->fixture();
        Project::factory()->create(['organization_id' => $org->id, 'name' => 'Скрытый проект', 'is_archived' => false]);
        Project::factory()->create(['name' => 'Чужая организация', 'is_archived' => false]);
        $report = $this->builder->build($this->plan(['columns' => ['id', 'name', 'status']]), $actor, $org->id);

        self::assertCount(1, $report['sections'][0]['rows']);
        self::assertSame($visible->id, $report['sections'][0]['rows'][0][0]);
        self::assertSame($visible->name, $report['sections'][0]['rows'][0][1]);
        self::assertSame(1, $report['sections'][0]['source_row_count']);
        self::assertTrue($this->policy->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_source_does_not_offer_project_budget_totals_without_an_available_currency(): void
    {
        [$org, $actor] = $this->fixture();
        $fields = $this->sources->resolve($actor, $org->id, 'projects', 'project', null)['fields'];
        self::assertArrayHasKey('budget_amount', $fields);
        self::assertArrayNotHasKey('budget_currency', $fields);
        self::assertSame(['count'], $fields['budget_amount']['aggregations']);
        $this->expectException(ValidationException::class);
        $this->builder->build($this->plan(['columns' => [], 'metrics' => [['field' => 'budget_amount', 'operation' => 'sum', 'label' => 'Бюджет']]]), $actor, $org->id);
    }

    public function test_publication_freshness_accepts_signed_snapshot_and_rechecks_age_actor_and_current_acl(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $guard = new \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard($this->policy);
        $reference = $this->builder->build($this->plan(), $actor, $org->id)['source_refs'][0];
        self::assertTrue($guard->fresh($actor, $org->id, [$reference]));
        $persisted = json_decode(DB::selectOne('SELECT CAST(? AS jsonb) AS payload', [json_encode($reference, JSON_THROW_ON_ERROR)])->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($guard->fresh($actor, $org->id, [$persisted]));
        $project->update(['name' => 'Название после формирования']);
        self::assertTrue($guard->fresh($actor, $org->id, [$reference]));
        $expired = $this->references->create($actor, $org->id, $reference['_report_scope'], $reference['checked_fields'],
            $reference['_report_ids'], $reference['source_version'], now()->subMinutes(6)->toISOString());
        self::assertFalse($guard->fresh($actor, $org->id, [$expired]));
        $other = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $other->organizations()->attach($org->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        self::assertFalse($guard->fresh($other, $org->id, [$reference]));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($guard->fresh($actor, $org->id, [$reference]));
    }

    public function test_contract_groups_use_exact_decimal_totals_and_date_filters(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '0.10');
        $this->contract($org, $project, '0.20');
        $this->contract($org, $project, '999.00', 'RUB', '2025-01-01');
        $hidden = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $this->contract($org, $hidden, '500.00');
        $section = $this->contractsSection(['filters' => [['field' => 'date', 'operator' => 'gte', 'value' => '2026-01-01']],
            'columns' => ['currency'], 'group_by' => ['currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);

        self::assertSame([['RUB', 2, '0.30']], $report['sections'][0]['rows']);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_financial_fields_are_not_available_as_columns_filters_or_ordering_without_permission(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '1.00');
        $this->denied = ['finance.view'];
        $source = $this->sources->resolve($actor, $org->id, 'contracts', 'contract', null);
        self::assertArrayNotHasKey('total_amount', $source['fields']);
        foreach ([['columns' => ['total_amount']], ['filters' => [['field' => 'total_amount', 'operator' => 'gt', 'value' => '0']]],
            ['order_by' => [['field' => 'total_amount', 'direction' => 'desc']]], ['metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма']]]] as $override) {
            try {
                $this->builder->build($this->plan($this->contractsSection($override)), $actor, $org->id);
                self::fail('Restricted field was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('fields', $exception->errors());
            }
        }
    }

    public function test_download_reference_rejects_revoked_financial_permission_and_tampering(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '10.01');
        $report = $this->builder->build($this->plan($this->contractsSection(['columns' => ['number', 'total_amount']])), $actor, $org->id);
        $reference = $report['source_refs'][0];
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $tampered = $reference;
        $tampered['_report_ids'] = [];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $tampered));
        $this->denied = ['finance.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
    }

    public function test_download_reference_rejects_revoked_project_and_other_actor(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $reference = $this->builder->build($this->plan(), $actor, $org->id)['source_refs'][0];
        $other = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $other->organizations()->attach($org->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        self::assertFalse($this->references->canReadReference($other, $org->id, $reference));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
    }

    public function test_mixed_currencies_require_grouping_and_preserve_each_total(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '1.01', 'RUB');
        $this->contract($org, $project, '2.02', 'USD');
        $section = $this->contractsSection(['columns' => [], 'metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма']]]);
        try {
            $this->builder->build($this->plan($section), $actor, $org->id);
            self::fail('Currencies were mixed');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('metrics', $exception->errors());
        }
        $section['columns'] = $section['group_by'] = ['currency'];
        $section['order_by'] = [['field' => 'currency', 'direction' => 'asc']];
        self::assertSame([['RUB', '1.01'], ['USD', '2.02']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_secondary_currency_cannot_mask_mixed_currency_of_the_selected_amount(): void
    {
        [$org, $actor, $project] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('contracts', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('budget_currency')->nullable();
        });
        $this->contract($org, $project, '1.01', 'RUB');
        $this->contract($org, $project, '2.02', 'USD');
        DB::table('contracts')->where('organization_id', $org->id)->update(['budget_currency' => 'RUB']);
        $definition = (new AssistantDomainCatalog(AssistantDomainCatalog::defaults()))->definition('contracts');
        $catalog = new AssistantDomainCatalog([new \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition(
            $definition->domain, $definition->module, $definition->entityType, $definition->permissions,
            [...$definition->fields, 'budget_currency'], $definition->schemas, $definition->operations, $definition->navigation,
            $definition->sourceType, $definition->entityTypes, $definition->fieldPermissions, $definition->entityPermissions)]);
        $sources = new AssistantCustomReportSource($catalog, $this->policy);
        self::assertArrayHasKey('budget_currency', $sources->resolve($actor, $org->id, 'contracts', 'contract', null)['fields']);
        $builder = new AssistantCustomReportBuilder($sources, new AssistantCustomReportReference($sources, $this->policy), $this->policy);
        $this->expectException(ValidationException::class);
        $builder->build($this->plan($this->contractsSection(['columns' => [],
            'metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма']]])), $actor, $org->id);
    }

    public function test_stock_and_minor_currency_metrics_reject_mixed_dimensions_before_totals(): void
    {
        [$org, $actor, $project] = $this->fixture();
        \Illuminate\Support\Facades\Schema::table('contracts', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->decimal('opening_on_hand', 20, 4)->nullable();
            $table->bigInteger('consumption_value_minor')->nullable();
            $table->bigInteger('unit_price_minor')->nullable();
            $table->string('unit_code')->nullable();
            $table->string('unit_dimension')->nullable();
        });
        $first = $this->contract($org, $project, '1.00', 'RUB');
        $second = $this->contract($org, $project, '2.00', 'USD');
        $first->getConnection()->table('contracts')->where('id', $first->id)->update([
            'opening_on_hand' => '10', 'consumption_value_minor' => 100, 'unit_price_minor' => 10, 'unit_code' => 'unit', 'unit_dimension' => 'mass']);
        $second->getConnection()->table('contracts')->where('id', $second->id)->update([
            'opening_on_hand' => '3', 'consumption_value_minor' => 200, 'unit_price_minor' => 20, 'unit_code' => 'unit', 'unit_dimension' => 'count']);
        $definition = (new AssistantDomainCatalog(AssistantDomainCatalog::defaults()))->definition('contracts');
        $catalog = new AssistantDomainCatalog([new \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition(
            $definition->domain, $definition->module, $definition->entityType, $definition->permissions,
            [...$definition->fields, 'opening_on_hand', 'consumption_value_minor', 'unit_price_minor', 'unit_code', 'unit_dimension'],
            $definition->schemas, $definition->operations, $definition->navigation, $definition->sourceType,
            $definition->entityTypes, $definition->fieldPermissions, $definition->entityPermissions)]);
        $sources = new AssistantCustomReportSource($catalog, $this->policy);
        $builder = new AssistantCustomReportBuilder($sources, new AssistantCustomReportReference($sources, $this->policy), $this->policy);
        self::assertSame(['unit_code', 'unit_dimension'], $sources->resolve($actor, $org->id, 'contracts', 'contract', null)['fields']['opening_on_hand']['dimensions']);
        foreach (['opening_on_hand', 'consumption_value_minor'] as $field) {
            try {
                $builder->build($this->plan($this->contractsSection(['columns' => [], 'metrics' => [['field' => $field, 'operation' => 'sum', 'label' => 'Итог']]])), $actor, $org->id);
                self::fail('Mixed dimensions must not produce a total: '.$field);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('metrics', $exception->errors());
            }
        }
        DB::table('contracts')->where('id', $second->id)->update(['currency' => 'RUB']);
        try {
            $builder->build($this->plan($this->contractsSection(['columns' => [], 'metrics' => [['field' => 'unit_price_minor', 'operation' => 'avg', 'label' => 'Цена']]])), $actor, $org->id);
            self::fail('Prices with mixed quantity dimensions must not be averaged');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('metrics', $exception->errors());
        }
        $section = $this->contractsSection(['columns' => ['unit_dimension', 'unit_code'], 'group_by' => ['unit_dimension', 'unit_code'],
            'metrics' => [['field' => 'opening_on_hand', 'operation' => 'sum', 'label' => 'Остаток']],
            'order_by' => [['field' => 'unit_dimension', 'direction' => 'asc']]]);
        self::assertSame([['count', 'unit', '3.0000'], ['mass', 'unit', '10.0000']], $builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_empty_selection_has_zero_count_and_verified_scope(): void
    {
        [$org, $actor] = $this->fixture();
        $report = $this->builder->build($this->plan(['columns' => [], 'filters' => [['field' => 'name', 'operator' => 'eq', 'value' => 'Нет такого проекта']],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Проектов']]]), $actor, $org->id);

        self::assertSame([[0]], $report['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $this->denied = ['projects.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_sql_identifiers_and_invalid_dates_are_rejected(): void
    {
        [$org, $actor] = $this->fixture();
        foreach ([['columns' => ['name; DROP TABLE projects']], ['filters' => [['field' => 'start_date', 'operator' => 'eq', 'value' => '2026-02-31']]],
            ['metrics' => [['field' => 'id', 'operation' => 'sum', 'label' => 'Сумма ID']], 'columns' => []]] as $section) {
            try {
                $this->builder->build($this->plan($section), $actor, $org->id);
                self::fail('Unsafe plan accepted');
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
        self::assertTrue(DB::getSchemaBuilder()->hasTable('projects'));
    }

    public function test_explicit_hidden_project_is_denied(): void
    {
        [$org, $actor] = $this->fixture();
        $hidden = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $this->expectException(AccessDeniedHttpException::class);
        $this->builder->build($this->plan(['project_id' => $hidden->id]), $actor, $org->id);
    }

    public function test_related_project_names_are_readable_and_have_separate_current_access_proof(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '10.00');
        $report = $this->builder->build($this->plan($this->contractsSection(['project_id' => $project->id,
            'columns' => ['project_id', 'number']])), $actor, $org->id);

        self::assertSame('#'.$project->id.' — '.$project->name, $report['sections'][0]['rows'][0][0]);
        self::assertCount(2, $report['source_refs']);
        foreach ($report['source_refs'] as $reference) {
            self::assertSame($project->id, $reference['project_id']);
            self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        }
    }

    public function test_more_than_limit_is_rejected_before_partial_total_is_published(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contractor = Contractor::query()->create(['organization_id' => $org->id, 'name' => 'Для лимита']);
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $rows[] = ['organization_id' => $org->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
                'number' => 'LIMIT-'.$index, 'date' => '2026-10-01', 'total_amount' => '0.10', 'currency' => 'RUB', 'status' => 'active'];
        }
        DB::table('contracts')->insert($rows);
        $this->expectException(ValidationException::class);
        $this->builder->build($this->plan($this->contractsSection(['columns' => [],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Количество']]])), $actor, $org->id);
    }

    public function test_multi_section_report_can_reuse_columns_without_cross_section_distinct_errors(): void
    {
        [$org, $actor] = $this->fixture();
        $plan = $this->plan();
        $plan['sections'][] = $plan['sections'][0];
        $plan['sections'][1]['title'] = 'Повторный срез';

        self::assertCount(2, $this->builder->build($plan, $actor, $org->id)['sections']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fileFormats')]
    public function test_tool_stores_real_file_once_exposes_folders_and_downloads_with_correct_mime(string $format, string $signature): void
    {
        [$org, $actor] = $this->fixture();
        $objects = [];
        $files = $this->createMock(\App\Services\Storage\FileService::class);
        $files->expects($this->once())->method('putPrivate')->willReturnCallback(function (string $path, $stream, string $mime, string $hash) use (&$objects, $signature) {
            $objects[$path] = stream_get_contents($stream);
            self::assertStringStartsWith($signature, $objects[$path]);
            self::assertSame(hash('sha256', $objects[$path]), $hash);

            return new \App\Services\Storage\DTO\CurrentStoredFile($path, 'etag', strlen($objects[$path]), $hash, $mime);
        });
        $files->method('readCurrent')->willReturnCallback(static function (string $path) use (&$objects) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $objects[$path]);
            rewind($stream);

            return $stream;
        });
        $personal = new \App\Services\Storage\PersonalFileService($files);
        $access = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService($this->policy);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class, $access);
        $permissions = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
        $permissions->method('canExecuteTool')->willReturn(true);
        $tool = new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateCustomReportTool($this->builder,
            new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer,
            new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage($personal), $permissions);
        $plan = array_replace($this->plan(), ['format' => $format]);
        $result = $tool->execute($plan, $actor, $org);
        self::assertSame('success', $result['status']);
        self::assertSame($result, $tool->execute($plan, $actor, $org));
        self::assertSame(['reports'], array_map(static fn ($file): string => $file->original_name, $personal->paginate($org->id, $actor->id, [])->items()));
        self::assertSame(['assistant'], array_map(static fn ($file): string => $file->original_name, $personal->paginate($org->id, $actor->id, ['folder' => 'reports'])->items()));
        preg_match('#/reports/([^/]+)/download$#', $result['download_url'], $matches);
        $request = \Illuminate\Http\Request::create('/download');
        $request->setUserResolver(static fn () => $actor);
        $response = (new \App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController($files, $access))->download($request, $matches[1]);
        self::assertSame(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer::MIME_TYPES[$format], $response->headers->get('Content-Type'));
        self::assertStringContainsString('.'.$format, $response->headers->get('Content-Disposition'));
        ob_start();
        try {
            $response->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(hash('sha256', $objects[$result['storage_path']]), hash('sha256', $bytes));
        $actor->assignedProjects()->updateExistingPivot(Project::query()->where('organization_id', $org->id)->sole()->id, ['is_active' => false]);
        $this->expectException(AccessDeniedHttpException::class);
        $access->resolve($matches[1], $actor);
    }

    public function test_container_executor_publishes_private_pdf_from_fixed_report_tool(): void
    {
        [$org, $actor] = $this->fixture();
        (new \App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider(app()))->register();
        $files = $this->createMock(\App\Services\Storage\FileService::class);
        $files->expects($this->once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) {
            return new \App\Services\Storage\DTO\CurrentStoredFile($path, 'etag', strlen(stream_get_contents($stream)), $hash, $mime);
        });
        app()->instance(\App\Services\Storage\FileService::class, $files);
        $access = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService($this->policy);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class, $access);
        $report = $this->builder->build($this->plan(), $actor, $org->id);
        $storage = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage(new \App\Services\Storage\PersonalFileService($files));
        $stored = $storage->storePdf('%PDF-fixture', 'Остатки.pdf', $org, $actor, $report['source_refs'], $report['required_domains']);
        $tool = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
        $tool->method('getName')->willReturn('generate_warehouse_stock_report');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]);
        $tool->expects($this->exactly(2))->method('execute')->willReturn(['status' => 'success', ...$stored]);
        $registry = new \App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
        $registry->registerTool($tool);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry::class, $registry);
        $permissions = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
        $permissions->method('canExecuteTool')->willReturn(true);
        $permissions->method('isMutationTool')->willReturn(false);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class, $permissions);

        $result = app(\App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor::class)
            ->execute('generate_warehouse_stock_report', [], $actor, $org);
        self::assertSame('success', $result['status']);
        self::assertCount(1, $result['artifacts']);
        self::assertSame('Остатки.pdf', $result['artifacts'][0]['filename']);
        self::assertSame($stored['storage_path'], $result['artifacts'][0]['storage_path']);
        self::assertStringStartsWith('/api/v1/ai-assistant/reports/', $result['artifacts'][0]['url']);
        $other = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $other->organizations()->attach($org->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        self::assertSame([], app(\App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor::class)
            ->execute('generate_warehouse_stock_report', [], $other, $org)['artifacts']);
    }

    public static function fileFormats(): array
    {
        return ['xlsx' => ['xlsx', 'PK'], 'csv' => ['csv', "\xEF\xBB\xBF"], 'pdf' => ['pdf', '%PDF-']];
    }

    public function test_failed_access_registration_removes_only_newly_created_file(): void
    {
        [$org, $actor] = $this->fixture();
        $report = $this->builder->build($this->plan(), $actor, $org->id);
        $existing = \App\Models\PersonalFile::query()->create(['organization_id' => $org->id, 'user_id' => $actor->id,
            'original_name' => 'Сохранённый.csv', 'storage_key' => 'org-'.$org->id.'/personal-files/user-'.$actor->id.'/existing.csv',
            'mime_type' => 'text/csv', 'size' => 4, 'directory' => 'reports/assistant', 'is_folder' => false]);
        $newPath = null;
        $files = $this->createMock(\App\Services\Storage\FileService::class);
        $files->expects($this->once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$newPath) {
            $newPath = $path;

            return new \App\Services\Storage\DTO\CurrentStoredFile($path, 'etag', 4, $hash, $mime);
        });
        $files->expects($this->once())->method('deleteCurrent')->willReturnCallback(static function (string $path) use (&$newPath): void {
            self::assertSame($newPath, $path);
        });
        $access = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService($this->policy);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class, $access);
        $storage = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage(new \App\Services\Storage\PersonalFileService($files));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'body');
        rewind($stream);
        DB::connection()->beforeExecuting(static function (string $query): void {
            if (str_starts_with($query, 'insert into "ai_assistant_report_access"')) {
                throw new \RuntimeException('access_registration_failed');
            }
        });
        try {
            $storage->store($stream, 'Новый.csv', 'csv', $org, $actor, $report['source_refs'], $report['required_domains']);
            self::fail('Failed access registration was accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('access_registration_failed', $exception->getMessage());
            self::assertTrue($existing->fresh()->exists);
            self::assertFalse(\App\Models\PersonalFile::query()->where('storage_key', $newPath)->exists());
        } finally {
            fclose($stream);
        }
    }

    public function test_top_groups_are_sorted_by_exact_total_before_output_limit(): void
    {
        [$org, $actor, $project] = $this->fixture();
        foreach (['0.10', '0.20'] as $amount) {
            $this->contract($org, $project, $amount, 'RUB');
        }
        $this->contract($org, $project, '5.00', 'USD');
        $section = $this->contractsSection(['columns' => ['currency'], 'group_by' => ['currency'],
            'metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма']],
            'order_by' => [['field' => 'metric_1', 'direction' => 'desc']], 'row_limit' => 1]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);

        self::assertSame([['USD', '5.00']], $report['sections'][0]['rows']);
        self::assertSame(3, $report['sections'][0]['source_row_count']);
        self::assertTrue($report['sections'][0]['output_limited']);
        self::assertCount(3, $report['source_refs'][0]['_report_ids']);
    }

    public function test_numeric_groups_are_sorted_numerically_before_output_limit(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $this->contract($org, $project, '10.00');
        $this->contract($org, $project, '2.00');
        $section = $this->contractsSection(['columns' => ['total_amount'], 'group_by' => ['total_amount'],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Договоров']],
            'order_by' => [['field' => 'total_amount', 'direction' => 'asc']], 'row_limit' => 1]);
        self::assertSame([['2.00', 1]], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_pdf_renderer_emits_real_document_and_rejects_excess_rows(): void
    {
        $report = ['title' => 'Отчёт руководителю', 'format' => 'pdf', 'generated_at' => '2026-10-16T15:00:00Z',
            'sections' => [['title' => 'Договоры', 'headers' => ['Номер', 'Подрядчик', 'Сумма, ₽'],
                'source_row_count' => 2, 'rows' => [['ДГ-01', 'Подрядчик «Север»', '1000000.25'], ['ДГ-02', 'Подрядчик «Юг»', '2000000.50']]]]];
        $renderer = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
        $stream = $renderer->render($report);
        try {
            self::assertSame('%PDF-', fread($stream, 5));
        } finally {
            fclose($stream);
        }
        $report['sections'][0]['rows'] = array_fill(0, 301, ['ДГ-01', 'Подрядчик', '1.00']);
        $this->expectException(ValidationException::class);
        $renderer->render($report);
    }

    public function test_large_text_field_is_rejected_without_silent_truncation(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $project->update(['description' => str_repeat('Я', 4001)]);
        $this->expectException(ValidationException::class);
        $this->builder->build($this->plan(['columns' => ['name', 'description']]), $actor, $org->id);
    }

    public function test_prefetched_report_schema_is_bounded_and_excludes_revoked_financial_fields(): void
    {
        [$org, $actor] = $this->fixture();
        $this->denied = ['finance.view'];
        $query = 'Сделай Excel по договорам с суммами и статусами';
        $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($query, []);
        $hints = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints(
            new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->sources, $this->policy))->forRequest($actor, $org->id, $query, $understanding);

        self::assertCount(1, $hints);
        self::assertSame('contracts', $hints[0]['domain']);
        self::assertArrayHasKey('number', $hints[0]['fields']);
        self::assertArrayNotHasKey('total_amount', $hints[0]['fields']);
        self::assertLessThanOrEqual(40, count($hints[0]['fields']));
    }

    public function test_contract_source_matches_active_organization_placement_with_shared_and_revoked_access(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $own = $this->contract($org, $project, '100.00');
        $own->organizationViews()->delete();
        $foreign = Organization::factory()->create();
        $shared = $this->contract($foreign, $project, '200.00');
        $sharedView = \App\Models\ContractOrganizationView::query()->updateOrCreate(['contract_id' => $shared->id, 'organization_id' => $org->id], ['visibility' => 'active', 'version' => 1]);
        $archived = $this->contract($org, $project, '300.00');
        \App\Models\ContractOrganizationView::query()->updateOrCreate(['contract_id' => $archived->id, 'organization_id' => $org->id], ['visibility' => 'archived', 'version' => 1]);
        $revoked = $this->contract($org, $project, '400.00');
        \App\Models\ContractOrganizationView::query()->updateOrCreate(['contract_id' => $revoked->id, 'organization_id' => $org->id], ['visibility' => 'active', 'version' => 1, 'access_revoked_at' => now()]);
        $hiddenProject = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $this->contract($org, $hiddenProject, '500.00');
        $this->contract($foreign, $project, '600.00');
        $counterpartyOnly = $this->contract($foreign, $project, '700.00');
        $counterpartyOnly->organizationViews()->delete();
        $counterpartyOnly->contractor->update(['source_organization_id' => $org->id]);
        $linkedParty = $this->contract($foreign, $project, '800.00');
        $linkedParty->organizationViews()->delete();
        \App\Models\ContractParty::withoutEvents(static fn () => \App\Models\ContractParty::query()->create([
            'contract_id' => $linkedParty->id, 'side' => 'second', 'party_key' => 'second',
            'role' => 'contractor', 'linked_organization_id' => $org->id, 'name' => $org->name, 'snapshot' => [],
        ]));
        $source = $this->sources->resolve($actor, $org->id, 'contracts', 'contract', null);
        self::assertSame([$own->id, $shared->id, $linkedParty->id], $source['query']->orderBy('contracts.id')->pluck('contracts.id')->all());
        self::assertTrue($this->policy->canReadEntity($actor, $org->id, 'contract', $linkedParty->id));
        self::assertTrue($this->policy->canReadEntity($actor, $org->id, 'contract', $shared->id));
        self::assertTrue($this->policy->canReadEntity($actor, $org->id, 'contract', $archived->id));
        self::assertFalse($this->policy->canReadEntity($actor, $org->id, 'contract', $revoked->id));
        self::assertFalse($this->policy->canReadEntity($actor, $org->id, 'contract', $counterpartyOnly->id));
        $report = $this->builder->build($this->plan($this->contractsSection(['columns' => ['number']])), $actor, $org->id);
        self::assertSame(3, $report['sections'][0]['source_row_count']);
        self::assertTrue($this->policy->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $sharedView->update(['access_revoked_at' => now()]);
        self::assertFalse($this->policy->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_report_status_choices_are_canonical_and_valid_filter_returns_active_projects(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $project->update(['status' => 'active']);
        $source = $this->sources->resolve($actor, $org->id, 'projects', 'project', null);
        self::assertSame(['draft', 'active', 'completed', 'paused', 'cancelled'], array_column($source['fields']['status']['choices'], 'value'));
        $query = 'Сделай CSV по действующим проектам';
        $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($query, []);
        $hints = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints(
            new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->sources, $this->policy))->forRequest($actor, $org->id, $query, $understanding);
        self::assertSame($source['fields']['status']['choices'], $hints[0]['choices']['status']);
        $report = $this->builder->build($this->plan(['columns' => ['id', 'name'], 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']]]), $actor, $org->id);
        self::assertSame([[$project->id, $project->name]], $report['sections'][0]['rows']);
    }

    public function test_invalid_enum_filter_is_rejected_before_generating_a_misleading_empty_report(): void
    {
        [$org, $actor] = $this->fixture();
        foreach ([['eq', 'Активный'], ['in', ['active', 'unknown_status']], ['contains', 'active'], ['gt', 'active']] as [$operator, $value]) {
            try {
                $this->builder->build($this->plan(['filters' => [['field' => 'status', 'operator' => $operator, 'value' => $value]]]), $actor, $org->id);
                self::fail('Invalid enum filter must be rejected');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('filters', $error->errors());
            }
        }
    }

    public function test_machinery_report_matches_native_projection_before_filtering_grouping_and_limiting(): void
    {
        $this->extraModules = ['machinery-operations'];
        [$org, $actor, $project] = $this->fixture();
        $assets = [];
        foreach (['active', 'retired', 'retired'] as $index => $lifecycle) {
            $asset = \Tests\Support\MachineryOperationsAssetFactory::create($org->id,
                ['asset_code' => 'REPORT-'.$index, 'name' => 'Каноническая техника '.$index, 'current_project_id' => $project->id]);
            $asset->organizationAsset->updateQuietly(['lifecycle_status' => $lifecycle]);
            $asset->updateQuietly(['name' => 'Устаревшее имя', 'status' => 'available']);
            $assets[] = $asset->refresh()->load('organizationAsset.operationProfile');
        }
        $section = ['domain' => 'machinery', 'entity_type' => 'machinery_asset', 'project_id' => $project->id,
            'columns' => ['id', 'name', 'inventory_number', 'status', 'current_project_id'], 'order_by' => [['field' => 'name', 'direction' => 'asc']]];
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        foreach ($assets as $index => $asset) {
            $native = app(\App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetProjection::class)->project($asset);
            self::assertSame([$native['id'], $native['name'], $native['inventory_number'],
                \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactLabels::display('machinery_asset', 'status', $native['status']),
                '#'.$project->id.' — '.$project->name], $report['sections'][0]['rows'][$index]);
        }
        $grouped = array_replace($section, ['columns' => ['status'], 'group_by' => ['status'],
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'archived']],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Единиц']], 'order_by' => [], 'row_limit' => 1]);
        $summary = $this->builder->build($this->plan($grouped), $actor, $org->id);
        self::assertSame(2, $summary['sections'][0]['source_row_count']);
        self::assertSame(2, $summary['sections'][0]['rows'][0][1]);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $summary['source_refs'][0]));
    }

    public function test_machinery_projection_filters_large_source_before_match_limit(): void
    {
        $this->extraModules = ['machinery-operations'];
        config(['asset_registry.strict_canonical_reads' => false]);
        [$org, $actor, $project] = $this->fixture();
        $ordinary = [];
        for ($index = 0; $index < 1001; $index++) {
            $ordinary[] = ['organization_id' => $org->id, 'current_project_id' => $project->id,
                'asset_code' => 'BULK-'.$index, 'name' => 'Обычная техника '.$index, 'inventory_number' => 'BULK-'.$index,
                'ownership_type' => 'owned', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('machinery_assets')->insert($ordinary);
        foreach (['Бета', 'Альфа'] as $index => $name) {
            $asset = \Tests\Support\MachineryOperationsAssetFactory::create($org->id,
                ['asset_code' => 'MATCH-'.$index, 'name' => $name, 'current_project_id' => $project->id]);
            $asset->organizationAsset->updateQuietly(['lifecycle_status' => 'retired']);
            $asset->updateQuietly(['name' => 'Устаревшее имя', 'status' => 'available']);
        }
        $section = ['domain' => 'machinery', 'entity_type' => 'machinery_asset', 'project_id' => $project->id,
            'columns' => ['name'], 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'archived']],
            'order_by' => [['field' => 'name', 'direction' => 'asc']], 'row_limit' => 1];
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Альфа']], $report['sections'][0]['rows']);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertTrue($report['sections'][0]['output_limited']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));

        $grouped = array_replace($section, ['columns' => ['status'], 'group_by' => ['status'], 'order_by' => [],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Единиц']]]);
        $summary = $this->builder->build($this->plan($grouped), $actor, $org->id);
        self::assertSame(2, $summary['sections'][0]['source_row_count']);
        self::assertSame(2, $summary['sections'][0]['rows'][0][1]);

        $this->expectException(ValidationException::class);
        $this->builder->build($this->plan(array_replace($section, [
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'available']],
        ])), $actor, $org->id);
    }

    public function test_machinery_current_project_scope_and_download_recheck_ignore_stale_legacy_placement(): void
    {
        $this->extraModules = ['machinery-operations'];
        [$org, $actor, $project] = $this->fixture();
        $asset = \Tests\Support\MachineryOperationsAssetFactory::create($org->id,
            ['asset_code' => 'REPORT-MOVE', 'name' => 'Перемещённая техника', 'current_project_id' => $project->id]);
        $section = ['domain' => 'machinery', 'entity_type' => 'machinery_asset', 'project_id' => $project->id, 'columns' => ['id', 'name']];
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame(1, $report['sections'][0]['source_row_count']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $next = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($next->id, ['is_active' => true, 'role' => 'member']);
        $asset->organizationAsset->updateQuietly(['current_project_id' => $next->id]);
        self::assertSame(0, $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['source_row_count']);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        self::assertSame(1, $this->builder->build($this->plan(array_replace($section, ['project_id' => $next->id])), $actor, $org->id)['sections'][0]['source_row_count']);
        $actor->assignedProjects()->updateExistingPivot($next->id, ['is_active' => false]);
        self::assertSame(0, $this->builder->build($this->plan(array_replace($section, ['project_id' => null])), $actor, $org->id)['sections'][0]['source_row_count']);
    }

    public function test_overdue_task_report_projects_parent_scope_and_responsible_before_grouping(): void
    {
        $this->extraModules = ['schedule-management', 'users'];
        [$org, $actor, $project] = $this->fixture();
        $schedule = \App\Models\ProjectSchedule::query()->create(['organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Действующий график', 'created_by_user_id' => $actor->id, 'planned_start_date' => '2026-01-01', 'planned_end_date' => '2026-12-31']);
        foreach ([['not_started', 0], ['in_progress', 50], ['completed', 100], ['cancelled', 0], ['in_progress', 100]] as [$status, $progress]) {
            \App\Models\ScheduleTask::query()->create(['schedule_id' => $schedule->id, 'organization_id' => $org->id,
                'created_by_user_id' => $actor->id, 'assigned_user_id' => $actor->id, 'name' => 'Задача '.$status.' '.$progress,
                'task_type' => 'task', 'planned_start_date' => now()->subDays(5)->toDateString(), 'planned_end_date' => now()->subDay()->toDateString(),
                'planned_duration_days' => 4, 'planned_work_hours' => 8, 'quantity' => 10, 'progress_percent' => $progress, 'status' => $status]);
        }
        $section = ['domain' => 'schedule', 'entity_type' => 'schedule_task', 'columns' => ['name', 'project_id', 'assigned_user_id', 'status'],
            'filters' => [['field' => 'is_overdue', 'operator' => 'eq', 'value' => true]]];
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        foreach ($report['sections'][0]['rows'] as $row) {
            self::assertSame('#'.$project->id.' — '.$project->name, $row[1]);
            self::assertSame('#'.$actor->id.' — '.$actor->name, $row[2]);
        }
        $grouped = array_replace($section, ['columns' => ['project_id'], 'group_by' => ['project_id'],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Просроченных задач']]]);
        self::assertSame([['#'.$project->id.' — '.$project->name, 2]], $this->builder->build($this->plan($grouped), $actor, $org->id)['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_task_assignee_name_is_bounded_to_active_organization_members_without_users_directory_permission(): void
    {
        $this->extraModules = ['schedule-management'];
        $this->denied = ['users.view', 'admin.users.view'];
        [$org, $actor, $project] = $this->fixture();
        $colleague = User::factory()->create(['name' => 'Ответственный коллега', 'is_active' => true]);
        $colleague->organizations()->attach($org->id, ['is_active' => true]);
        $foreign = User::factory()->create(['name' => 'Чужое имя', 'is_active' => true]);
        $schedule = \App\Models\ProjectSchedule::query()->create(['organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'График', 'created_by_user_id' => $actor->id, 'planned_start_date' => '2026-01-01', 'planned_end_date' => '2026-12-31']);
        foreach ([$colleague, $foreign] as $assignee) {
            \App\Models\ScheduleTask::query()->create(['schedule_id' => $schedule->id, 'organization_id' => $org->id,
                'created_by_user_id' => $actor->id, 'assigned_user_id' => $assignee->id, 'name' => 'Задача '.$assignee->id,
                'task_type' => 'task', 'planned_start_date' => now()->subDays(2)->toDateString(), 'planned_end_date' => now()->subDay()->toDateString(),
                'planned_duration_days' => 1, 'status' => 'not_started']);
        }
        $section = ['domain' => 'schedule', 'entity_type' => 'schedule_task', 'columns' => ['name', 'assigned_user_name']];
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Задача '.$colleague->id, 'Ответственный коллега'], ['Задача '.$foreign->id, null]], $report['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        self::assertNull($this->policy->entityQuery($actor, $org->id, 'user'));
        $colleague->organizations()->updateExistingPivot($org->id, ['is_active' => false]);
        self::assertNull($this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows'][0][1]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_payment_report_resolves_direct_act_and_parent_contracts_before_grouping_and_limit(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $act = $this->paymentReportAct($contract, $project);
        $this->paymentReportDocument($org, $actor, $project, ['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id]);
        $invoice = $this->paymentReportDocument($org, $actor, $project, ['invoiceable_type' => \App\Models\ContractPerformanceAct::class, 'invoiceable_id' => $act->id]);
        $this->paymentReportDocument($org, $actor, $project, ['source_type' => Contract::class, 'source_id' => $contract->id]);
        $child = $this->paymentReportDocument($org, $actor, $project, ['source_type' => $invoice::class, 'source_id' => $invoice->id]);
        $this->paymentReportDocument($org, $actor, $project, ['source_type' => $child::class, 'source_id' => $child->id]);
        $sourceAct = $this->paymentReportDocument($org, $actor, $project, ['source_type' => $act::class, 'source_id' => $act->id]);
        $this->paymentReportDocument($org, $actor, $project, ['source_type' => $sourceAct::class, 'source_id' => $sourceAct->id]);
        $section = $this->paymentReportSection(['columns' => ['document_number', 'contract_number', 'document_date', 'due_date'],
            'filters' => [['field' => 'contract_number', 'operator' => 'eq', 'value' => $contract->number]],
            'order_by' => [['field' => 'contract_number', 'direction' => 'asc']], 'row_limit' => 2]);
        $summary = $this->paymentReportSection(['title' => 'Итоги', 'columns' => ['contract_number', 'currency'],
            'group_by' => ['contract_number', 'currency'], 'metrics' => [['field' => 'amount', 'operation' => 'sum', 'label' => 'Сумма'],
                ['field' => 'id', 'operation' => 'count', 'label' => 'Документов']]]);
        $plan = $this->plan($section);
        $plan['sections'][] = array_replace($plan['sections'][0], $summary);
        $report = $this->builder->build($plan, $actor, $org->id);
        self::assertSame(7, $report['sections'][0]['source_row_count']);
        self::assertCount(2, $report['sections'][0]['rows']);
        self::assertTrue($report['sections'][0]['output_limited']);
        self::assertSame(['Номер документа', 'Номер договора', 'Дата документа', 'Срок'], $report['sections'][0]['headers']);
        self::assertSame([[$contract->number, 'RUB', '70.00', 7]], $report['sections'][1]['rows']);
        self::assertCount(7, $report['source_refs'][0]['_report_scope']['contract_links']);
        foreach ($report['source_refs'] as $reference) {
            self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        }
        $stream = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer)->render($report);
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(stream_get_meta_data($stream)['uri']);
            self::assertSame(2, $book->getSheetCount());
            self::assertSame('Номер договора', $book->getSheet(0)->getCell('B5')->getValue());
            self::assertSame($contract->number, $book->getSheet(0)->getCell('B6')->getValue());
            self::assertSame($contract->number, $book->getSheet(1)->getCell('A6')->getValue());
            self::assertSame(70.0, (float) $book->getSheet(1)->getCell('C6')->getValue());
            $book->disconnectWorksheets();
        } finally {
            fclose($stream);
        }
        self::assertNotContains('contract_number', (new AssistantDomainCatalog(AssistantDomainCatalog::defaults()))->definition('finance')->fields);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoiceable'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['source'])]
    public function test_payment_contract_snapshot_rechecks_intermediate_act_access_and_blocks_download(string $actLink): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $act = $this->paymentReportAct($contract, $project);
        $parent = $this->paymentReportDocument($org, $actor, $project, [$actLink.'_type' => $act::class, $actLink.'_id' => $act->id]);
        $child = $this->paymentReportDocument($org, $actor, $project, ['source_type' => $parent::class, 'source_id' => $parent->id]);
        $plan = $this->plan($this->paymentReportSection(['filters' => [['field' => 'id', 'operator' => 'eq', 'value' => $child->id]]]));
        $report = $this->builder->build($plan, $actor, $org->id);
        self::assertSame([[$child->document_number, $contract->number]], $report['sections'][0]['rows']);
        $reference = $report['source_refs'][0];
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $tampered = $reference;
        $tampered['_report_scope']['contract_links'][0]['contract_id'] = null;
        self::assertFalse($this->references->canReadReference($actor, $org->id, $tampered));
        $files = $this->createMock(\App\Services\Storage\FileService::class);
        $files->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) {
            return new \App\Services\Storage\DTO\CurrentStoredFile($path, 'etag', strlen(stream_get_contents($stream)), $hash, $mime);
        });
        $access = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService($this->policy);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class, $access);
        $permissions = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
        $permissions->method('canExecuteTool')->willReturn(true);
        $tool = new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateCustomReportTool($this->builder,
            new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer,
            new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage(new \App\Services\Storage\PersonalFileService($files)), $permissions);
        $result = $tool->execute($plan, $actor, $org);
        self::assertSame('success', $result['status']);
        preg_match('#/reports/([^/]+)/download$#', $result['download_url'], $matches);
        self::assertNotEmpty($access->resolve($matches[1], $actor));
        $this->denied = ['contracts.performance_acts.view'];
        self::assertTrue($this->sources->resolve($actor, $org->id, 'finance', 'payment_document', null)['query']->whereKey($child->id)->exists());
        self::assertTrue($this->sources->resolve($actor, $org->id, 'contracts', 'contract', null)['query']->whereKey($contract->id)->exists());
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
        $this->expectException(AccessDeniedHttpException::class);
        $access->resolve($matches[1], $actor);
    }

    public function test_payment_contract_projection_handles_incomplete_links_and_bounded_metadata_with_current_access(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $contract->updateQuietly(['number' => 'META-PRIMARY']);
        $alternate = $this->contract($org, $project, '200.00');
        $alternate->updateQuietly(['number' => 'META-ALTERNATE']);
        $act = $this->paymentReportAct($contract, $project);
        $otherProject = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($otherProject->id, ['is_active' => true, 'role' => 'member']);
        $wrongProject = $this->contract($org, $otherProject, '300.00');
        $foreign = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->id]);
        $foreignContract = $this->contract($foreign, $foreignProject, '999.00');
        $parent = $this->paymentReportDocument($org, $actor, $project, ['invoiceable_type' => Contract::class, 'invoiceable_id' => $alternate->id]);
        $metadataParent = $this->paymentReportDocument($org, $actor, $project, ['metadata' => ['contract_id' => $contract->id]]);
        $partialDocument = $this->paymentReportDocument($org, $actor, $project,
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => null, 'source_type' => Contract::class, 'source_id' => $contract->id]);
        $hiddenDocument = $this->paymentReportDocument($org, $actor, $project,
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => $foreignContract->id, 'metadata' => ['contract_id' => $contract->id]]);
        $expected = [$parent->document_number => $alternate->number, $metadataParent->document_number => $contract->number,
            $partialDocument->document_number => $contract->number];
        foreach ([
            [['invoiceable_type' => null, 'invoiceable_id' => $foreignContract->id, 'source_type' => Contract::class, 'source_id' => $contract->id], $contract],
            [['invoiceable_type' => $act::class, 'invoiceable_id' => null, 'source_type' => $act::class, 'source_id' => $act->id], $contract],
            [['invoiceable_type' => $act::class, 'invoiceable_id' => null, 'source_type' => $parent::class, 'source_id' => $parent->id], $alternate],
            [['source_type' => Contract::class, 'source_id' => null, 'metadata' => ['contract_id' => $contract->id]], $contract],
            [['metadata' => ['contract_id' => $contract->id]], $contract],
            [['metadata' => ['contract_id' => (string) $contract->id]], $contract],
            [['source_type' => $metadataParent::class, 'source_id' => $metadataParent->id], $contract],
            [['source_type' => $parent::class, 'source_id' => $parent->id, 'metadata' => ['contract_id' => $contract->id]], $contract],
            [['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id, 'metadata' => ['contract_id' => $alternate->id]], $contract],
            [['metadata' => ['contract_id' => $foreignContract->id]], null],
            [['metadata' => ['contract_id' => $wrongProject->id]], null],
            [['metadata' => ['contract_id' => '9223372036854775807']], null],
            [['metadata' => ['contract_id' => '9223372036854775808']], null],
            [['metadata' => ['contract_id' => '99999999999999999999999999999999999']], null],
            [['metadata' => ['contract_id' => '1 OR 1=1']], null],
            [['metadata' => ['contract_id' => '1.5']], null],
            [['metadata' => ['contract_id' => '-1']], null],
            [['metadata' => ['contract_id' => 0]], null],
            [['metadata' => ['contract_id' => [$contract->id]]], null],
        ] as [$override, $related]) {
            $document = $this->paymentReportDocument($org, $actor, $project, $override);
            $expected[$document->document_number] = $related?->number;
        }
        $plan = $this->plan($this->paymentReportSection());
        $report = $this->builder->build($plan, $actor, $org->id);
        $actual = array_column($report['sections'][0]['rows'], 1, 0);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame(count($expected), $report['sections'][0]['source_row_count']);
        self::assertArrayNotHasKey($hiddenDocument->document_number, $actual);
        $reference = $report['source_refs'][0];
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $count = count(array_filter($expected, static fn (?string $number): bool => $number === $contract->number));
        $summary = $this->builder->build($this->plan($this->paymentReportSection(['columns' => ['contract_number', 'currency'],
            'filters' => [['field' => 'contract_number', 'operator' => 'eq', 'value' => $contract->number]],
            'group_by' => ['contract_number', 'currency'], 'metrics' => [['field' => 'amount', 'operation' => 'sum', 'label' => 'Сумма'],
                ['field' => 'id', 'operation' => 'count', 'label' => 'Документов']]])), $actor, $org->id);
        self::assertSame([[$contract->number, 'RUB', sprintf('%d.00', $count * 10), $count]], $summary['sections'][0]['rows']);
        $metadataParent->updateQuietly(['metadata' => ['contract_id' => $alternate->id]]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
        $metadataParent->updateQuietly(['metadata' => ['contract_id' => $contract->id]]);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->policy->canReadEntity($actor, $org->id, 'payment_document', $partialDocument->id));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        self::assertTrue($this->policy->canReadEntity($actor, $org->id, 'payment_document', $partialDocument->id));
        $actor->organizations()->updateExistingPivot($org->id, ['is_active' => false]);
        self::assertFalse($this->policy->canReadEntity($actor, $org->id, 'payment_document', $partialDocument->id));
    }

    public function test_projectless_multi_contract_payments_resolve_only_currently_accessible_contracts_and_acts(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $contract->updateQuietly(['project_id' => null, 'is_multi_project' => true]);
        $contract->projects()->attach($project->id);
        $act = $this->paymentReportAct($contract, $project);
        $documents = [];
        foreach ([['invoiceable_type' => $act::class, 'invoiceable_id' => $act->id],
            ['source_type' => $act::class, 'source_id' => $act->id],
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id],
            ['source_type' => Contract::class, 'source_id' => $contract->id],
            ['metadata' => ['contract_id' => $contract->id]]] as $link) {
            $documents[] = $this->paymentReportDocument($org, $actor, $project, array_replace($link, ['project_id' => null]));
        }
        $documents[] = $this->paymentReportDocument($org, $actor, $project, ['project_id' => null,
            'source_type' => $documents[0]::class, 'source_id' => $documents[0]->id]);
        $unassigned = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $hiddenContract = $this->contract($org, $unassigned, '900.00');
        $hiddenContract->updateQuietly(['project_id' => null, 'is_multi_project' => true]);
        $hiddenContract->projects()->attach($unassigned->id);
        $hiddenAct = $this->paymentReportAct($hiddenContract, $unassigned);
        $hiddenDocument = $this->paymentReportDocument($org, $actor, $project, ['project_id' => null,
            'invoiceable_type' => $hiddenAct::class, 'invoiceable_id' => $hiddenAct->id]);
        $unresolved = $this->paymentReportDocument($org, $actor, $project, ['project_id' => null,
            'source_type' => $hiddenAct::class, 'source_id' => $hiddenAct->id]);
        $foreign = Organization::factory()->create();
        $foreignContract = $this->contract($foreign, Project::factory()->create(['organization_id' => $foreign->id]), '999.00');
        $foreignContract->updateQuietly(['project_id' => null, 'is_multi_project' => true]);
        $foreignSource = $this->paymentReportDocument($org, $actor, $project, ['project_id' => null,
            'source_type' => Contract::class, 'source_id' => $foreignContract->id]);
        $report = $this->builder->build($this->plan($this->paymentReportSection()), $actor, $org->id);
        $expected = [];
        foreach ($documents as $document) {
            $expected[] = [$document->document_number, $contract->number];
        }
        $expected[] = [$unresolved->document_number, null];
        $expected[] = [$foreignSource->document_number, null];
        self::assertSame($expected, $report['sections'][0]['rows']);
        self::assertNotContains($hiddenDocument->document_number, array_column($report['sections'][0]['rows'], 0));
        $summary = $this->builder->build($this->plan($this->paymentReportSection(['columns' => ['contract_number', 'currency'],
            'filters' => [['field' => 'contract_number', 'operator' => 'eq', 'value' => $contract->number]],
            'group_by' => ['contract_number', 'currency'], 'metrics' => [['field' => 'amount', 'operation' => 'sum', 'label' => 'Сумма'],
                ['field' => 'id', 'operation' => 'count', 'label' => 'Документов']]])), $actor, $org->id);
        self::assertSame([[$contract->number, 'RUB', '60.00', 6]], $summary['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_payment_contract_projection_fences_cycles_depth_foreign_and_project_links(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $chain = $this->paymentReportDocument($org, $actor, $project, ['source_type' => Contract::class, 'source_id' => $contract->id]);
        $atLimit = $chain;
        for ($depth = 1; $depth <= 33; $depth++) {
            $chain = $this->paymentReportDocument($org, $actor, $project, ['source_type' => $chain::class, 'source_id' => $chain->id]);
            if ($depth === 32) {
                $atLimit = $chain;
            }
        }
        $cycle = $this->paymentReportDocument($org, $actor, $project);
        $cycle->update(['source_type' => $cycle::class, 'source_id' => $cycle->id]);
        $foreign = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->id]);
        $foreignContract = $this->contract($foreign, $foreignProject, '100.00');
        $foreignSource = $this->paymentReportDocument($org, $actor, $project, ['source_type' => Contract::class, 'source_id' => $foreignContract->id]);
        $otherProject = Project::factory()->create(['organization_id' => $org->id]);
        $actor->assignedProjects()->attach($otherProject->id, ['is_active' => true, 'role' => 'member']);
        $otherContract = $this->contract($org, $otherProject, '100.00');
        $wrongProject = $this->paymentReportDocument($org, $actor, $project, ['source_type' => Contract::class, 'source_id' => $otherContract->id]);
        $multi = $this->contract($org, $project, '100.00');
        $multi->update(['project_id' => null, 'is_multi_project' => true]);
        $multi->projects()->attach($project->id);
        $multiSource = $this->paymentReportDocument($org, $actor, $project, ['source_type' => Contract::class, 'source_id' => $multi->id]);
        $ids = [$atLimit->id, $chain->id, $cycle->id, $foreignSource->id, $wrongProject->id, $multiSource->id];
        $section = $this->paymentReportSection(['filters' => [['field' => 'id', 'operator' => 'in', 'value' => $ids]]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([[$atLimit->document_number, $contract->number], [$chain->document_number, null], [$cycle->document_number, null],
            [$foreignSource->document_number, null], [$wrongProject->document_number, null], [$multiSource->document_number, $multi->number]], $report['sections'][0]['rows']);
        $reference = $report['source_refs'][0];
        self::assertTrue($this->references->canReadReference($actor, $org->id, $reference));
        $multi->projects()->detach($project->id);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $reference));
    }

    public function test_payment_report_without_contract_permission_keeps_plain_fields_and_rejects_virtual_fields(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $document = $this->paymentReportDocument($org, $actor, $project);
        $this->denied = ['contracts.view'];
        $source = $this->sources->resolve($actor, $org->id, 'finance', 'payment_document', null);
        self::assertArrayNotHasKey('contract_number', $source['fields']);
        self::assertArrayNotHasKey('contract_id', $source['fields']);
        $report = $this->builder->build($this->plan($this->paymentReportSection(['columns' => ['document_number', 'document_date']])), $actor, $org->id);
        self::assertSame([[$document->document_number, '2026-10-15']], $report['sections'][0]['rows']);
        foreach ([['columns' => ['contract_number']], ['filters' => [['field' => 'contract_number', 'operator' => 'eq', 'value' => 'secret']]],
            ['order_by' => [['field' => 'contract_number', 'direction' => 'asc']]]] as $override) {
            try {
                $this->builder->build($this->plan($this->paymentReportSection($override)), $actor, $org->id);
                self::fail('Restricted virtual field accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('fields', $exception->errors());
            }
        }
    }

    public function test_report_source_hints_recognize_act_word_forms_and_preserve_financial_access(): void
    {
        [$org, $actor] = $this->fixture();
        $hints = new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints(
            new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->sources, $this->policy);
        $resolver = new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
        $query = 'Сделай Excel по утверждённым актам проекта «Учебный ПТО — ремонт офиса 100 м² — 13.10.2026» за 13–17 октября 2026 года: номер, дата, сумма и валюта. На втором листе — количество актов и общая сумма.';
        foreach ([$query, 'Выгрузи CSV по актам проекта', 'Сделай Excel по акту проекта',
            'Сделай Excel: акты проекта', 'Сделай Excel: список актов выполненных работ', 'Сделай Excel с актом.'] as $question) {
            $sources = array_column($hints->forRequest($actor, $org->id, $question, $resolver->resolve($question)), null, 'domain');
            self::assertArrayHasKey('acts', $sources, $question);
            $act = $sources['acts'];
            self::assertSame('performance_act', $act['entity_type']);
            self::assertSame('string', $act['fields']['number']);
            self::assertSame('date', $act['fields']['date']);
            self::assertSame('boolean', $act['fields']['is_approved']);
            self::assertSame('is_approved', $act['preferred_fields']['approval']);
            self::assertSame('number', $act['fields']['total_amount']);
            self::assertContains('sum', $act['metrics']['total_amount']);
            self::assertContains('currency', $act['dimensions']['total_amount']);
        }
        foreach (['Сделай Excel по активным проектам', 'Сделай Excel по фактическим срокам проекта',
            'Сделай Excel по контрактам проекта', 'Сделай Excel по редакторам организации'] as $question) {
            self::assertNotContains('acts', array_column($hints->forRequest($actor, $org->id, $question, $resolver->resolve($question)), 'domain'));
        }
        self::assertSame([], $hints->forRequest($actor, $org->id, 'Покажи акты проекта без файлов', $resolver->resolve('Покажи акты проекта без файлов')));
        $this->denied = ['finance.view'];
        $sources = array_column($hints->forRequest($actor, $org->id, $query, $resolver->resolve($query)), null, 'domain');
        self::assertArrayHasKey('acts', $sources);
        self::assertArrayNotHasKey('total_amount', $sources['acts']['fields']);
        self::assertArrayNotHasKey('total_amount', $sources['acts']['metrics']);
        self::assertSame('date', $sources['acts']['fields']['date']);
        $this->denied = ['contracts.performance_acts.view'];
        self::assertNotContains('acts', array_column($hints->forRequest($actor, $org->id, $query, $resolver->resolve($query)), 'domain'));
    }

    public function test_performance_act_report_aliases_preserve_filters_sort_totals_and_financial_access(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '100.00');
        $first = $this->paymentReportAct($contract, $project);
        $first->updateQuietly(['act_document_number' => 'ACT-A', 'act_date' => '2026-10-13', 'amount' => '10.00']);
        $second = $this->paymentReportAct($contract, $project);
        $second->updateQuietly(['act_document_number' => 'ACT-B', 'act_date' => '2026-10-14', 'amount' => '20.00']);
        $unapproved = $this->paymentReportAct($contract, $project);
        $unapproved->updateQuietly(['act_document_number' => 'ACT-Y', 'act_date' => '2026-10-14', 'amount' => '500.00', 'is_approved' => false]);
        $foreign = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->id]);
        $foreignAct = $this->paymentReportAct($this->contract($foreign, $foreignProject, '999.00'), $foreignProject);
        $foreignAct->updateQuietly(['act_document_number' => 'ACT-Z', 'act_date' => '2026-10-14', 'amount' => '999.00']);
        $source = $this->sources->resolve($actor, $org->id, 'acts', 'performance_act', null);
        self::assertSame('date', $source['fields']['date']['type']);
        self::assertSame('number', $source['fields']['total_amount']['type']);
        self::assertSame('boolean', $source['fields']['is_approved']['type'] ?? null);
        self::assertArrayNotHasKey('amount', $source['fields']);
        $section = ['title' => 'Утверждённые акты', 'domain' => 'acts', 'entity_type' => 'performance_act',
            'columns' => ['number', 'date', 'total_amount', 'currency'],
            'filters' => [['field' => 'date', 'operator' => 'gte', 'value' => '2026-10-13'],
                ['field' => 'number', 'operator' => 'in', 'value' => ['ACT-A', 'ACT-B', 'ACT-Y', 'ACT-Z']],
                ['field' => 'is_approved', 'operator' => 'eq', 'value' => true]],
            'order_by' => [['field' => 'number', 'direction' => 'desc']], 'row_limit' => 1];
        $summary = array_replace($section, ['title' => 'Итоги', 'columns' => ['currency'], 'group_by' => ['currency'],
            'metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
                ['field' => 'id', 'operation' => 'count', 'label' => 'Актов']], 'order_by' => [], 'row_limit' => 100]);
        $plan = $this->plan($section);
        $plan['sections'][] = array_replace($plan['sections'][0], $summary);
        $report = $this->builder->build($plan, $actor, $org->id);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertSame([['ACT-B', '2026-10-14', '20.00', 'RUB']], $report['sections'][0]['rows']);
        self::assertSame([['RUB', '30.00', 2]], $report['sections'][1]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $stream = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer)->render($report);
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(stream_get_meta_data($stream)['uri']);
            self::assertSame(2, $book->getSheetCount());
            self::assertSame('ACT-B', $book->getSheet(0)->getCell('A6')->getValue());
            self::assertSame(20.0, (float) $book->getSheet(0)->getCell('C6')->getValue());
            self::assertSame(30.0, (float) $book->getSheet(1)->getCell('B6')->getValue());
            $book->disconnectWorksheets();
        } finally {
            fclose($stream);
        }
        $this->denied = ['finance.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $fields = $this->sources->resolve($actor, $org->id, 'acts', 'performance_act', null)['fields'];
        self::assertArrayNotHasKey('total_amount', $fields);
        self::assertArrayNotHasKey('amount', $fields);
        $plain = $this->builder->build($this->plan(array_replace($section, ['columns' => ['number', 'date']])), $actor, $org->id);
        self::assertSame([['ACT-B', '2026-10-14']], $plain['sections'][0]['rows']);
        foreach ([['columns' => ['total_amount']], ['filters' => [['field' => 'total_amount', 'operator' => 'gt', 'value' => 0]]],
            ['order_by' => [['field' => 'total_amount', 'direction' => 'desc']]]] as $override) {
            try {
                $this->builder->build($this->plan(array_replace($section, ['columns' => ['number', 'date']], $override)), $actor, $org->id);
                self::fail('Restricted act amount accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('fields', $exception->errors());
            }
        }
    }

    private function paymentReportSection(array $override = []): array
    {
        return array_replace(['title' => 'Платежи', 'domain' => 'finance', 'entity_type' => 'payment_document',
            'columns' => ['document_number', 'contract_number']], $override);
    }

    private function paymentReportAct(Contract $contract, Project $project): \App\Models\ContractPerformanceAct
    {
        return \App\Models\ContractPerformanceAct::withoutEvents(static fn () => \App\Models\ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => 'ACT-'.bin2hex(random_bytes(4)),
            'act_date' => '2026-10-13', 'period_start' => '2026-10-01', 'period_end' => '2026-10-13',
            'amount' => '100.00', 'currency' => 'RUB', 'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]));
    }

    private function paymentReportDocument(Organization $org, User $actor, Project $project, array $override = []): \App\BusinessModules\Core\Payments\Models\PaymentDocument
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentDocument::withoutEvents(static fn () => \App\BusinessModules\Core\Payments\Models\PaymentDocument::query()->create(array_replace([
            'organization_id' => $org->id, 'project_id' => $project->id, 'payer_organization_id' => $org->id,
            'payee_organization_id' => $org->id, 'document_type' => \App\BusinessModules\Core\Payments\Enums\PaymentDocumentType::INVOICE,
            'invoice_type' => \App\BusinessModules\Core\Payments\Enums\InvoiceType::ACT, 'invoiceable_type' => null, 'invoiceable_id' => null,
            'document_number' => 'PAY-'.bin2hex(random_bytes(4)), 'document_date' => '2026-10-15', 'due_date' => '2026-10-20',
            'direction' => \App\BusinessModules\Core\Payments\Enums\InvoiceDirection::OUTGOING,
            'status' => \App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus::APPROVED,
            'amount' => '10.00', 'paid_amount' => '0.00', 'remaining_amount' => '10.00', 'currency' => 'RUB', 'created_by_user_id' => $actor->id,
        ], $override)));
    }

    private function fixture(): array
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $actor->organizations()->attach($org->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $org->id, 'name' => 'Доступная стройка', 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);

        return [$org, $actor, $project];
    }

    private function plan(array $section = []): array
    {
        return ['title' => 'Отчёт руководителя', 'format' => 'xlsx', 'sections' => [array_replace([
            'title' => 'Проекты', 'domain' => 'projects', 'entity_type' => 'project', 'project_id' => null,
            'columns' => ['id', 'name'], 'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [],
        ], $section)]];
    }

    public function test_counterparty_name_uses_shared_contract_snapshot_without_foreign_private_fields(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $foreign = Organization::factory()->create();
        $contract = $this->contract($foreign, $project, '200.10');
        $view = \App\Models\ContractOrganizationView::query()->updateOrCreate(
            ['contract_id' => $contract->id, 'organization_id' => $org->id], ['visibility' => 'active', 'version' => 1]);
        $contract->parties()->delete();
        foreach (['first' => [$foreign->id, '  Генподрядчик  '], 'second' => [$org->id, $org->name]] as $side => [$linked, $name]) {
            \App\Models\ContractParty::withoutEvents(static fn () => \App\Models\ContractParty::query()->create([
                'contract_id' => $contract->id, 'side' => $side, 'party_key' => $side,
                'role' => 'contractor', 'linked_organization_id' => $linked, 'name' => $name,
                'email' => 'private@example.test', 'inn' => 'private-inn', 'snapshot' => ['phone' => 'private-phone'],
            ]));
        }
        $section = $this->contractsSection(['columns' => ['number', 'counterparty_name'],
            'filters' => [['field' => 'counterparty_name', 'operator' => 'contains', 'value' => 'генподряд']],
            'order_by' => [['field' => 'counterparty_name', 'direction' => 'asc']]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([[$contract->number, 'Генподрядчик']], $report['sections'][0]['rows']);
        self::assertSame(['Номер', 'Контрагент'], $report['sections'][0]['headers']);
        self::assertStringNotContainsString('private-', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $contract->firstParty()->update(['name' => 'Новое название']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $view->update(['access_revoked_at' => now()]);
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    public function test_counterparty_grouping_keeps_exact_totals_and_legacy_names_scoped(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        foreach (['0.10', '0.20'] as $amount) {
            $contract = $this->contract($org, $project, $amount);
            $contract->parties()->delete();
        }
        $foreign = Organization::factory()->create();
        $shared = $this->contract($foreign, $project, '50.00');
        $shared->parties()->delete();
        \App\Models\ContractOrganizationView::query()->updateOrCreate(
            ['contract_id' => $shared->id, 'organization_id' => $org->id], ['visibility' => 'active', 'version' => 1]);
        $shared->contractor->update(['name' => 'Скрытое имя чужого справочника']);
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ], 'order_by' => [['field' => 'counterparty_name', 'direction' => 'asc']]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Подрядчик', 'RUB', 2, '0.30'], [null, 'RUB', 1, '50.00']], $report['sections'][0]['rows']);
        self::assertStringNotContainsString('Скрытое имя', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame(3, $report['sections'][0]['source_row_count']);
        self::assertCount(2, $report['source_refs']);
        $this->denied = ['contractors.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][1]));
        self::assertSame([[null, 'RUB', 3, '50.30']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_counterparty_projection_does_not_replace_self_execution_with_legacy_contractor(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $contract = $this->contract($org, $project, '10.00');
        $contract->update(['is_self_execution' => true]);
        $contract->parties()->delete();
        foreach (['first', 'second'] as $side) {
            \App\Models\ContractParty::withoutEvents(static fn () => \App\Models\ContractParty::query()->create([
                'contract_id' => $contract->id, 'side' => $side, 'party_key' => $side,
                'role' => 'contractor', 'linked_organization_id' => $org->id, 'name' => $org->name, 'snapshot' => [],
            ]));
        }
        $section = $this->contractsSection(['columns' => ['counterparty_name'],
            'filters' => [['field' => 'counterparty_name', 'operator' => 'is_null', 'value' => null]]]);
        self::assertSame([[null]], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        $source = $this->sources->resolve($actor, $org->id, 'contracts', 'contract', null);
        self::assertSame(['count'], $source['fields']['counterparty_name']['aggregations']);
        $query = 'Сделай Excel по договорам: номер, подрядчик и сумма';
        $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($query, []);
        $hints = (new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints(
            new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $this->sources, $this->policy))->forRequest($actor, $org->id, $query, $understanding);
        self::assertSame('counterparty_name', $hints[0]['preferred_fields']['counterparty']);
        $legacy = $this->contract($org, $project, '10.00');
        $legacy->parties()->delete();
        $legacy->contractor->update(['source_organization_id' => $org->id]);
        DB::table('contracts')->where('id', $legacy->id)->update(['is_self_execution' => true, 'contract_side_type' => 'contract']);
        self::assertSame([[null], [null]], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_legacy_supply_counterparty_uses_authorized_supplier_and_rechecks_its_reference(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $supplier = \App\Models\Supplier::query()->create(['organization_id' => $org->id, 'name' => '  Поставщик материалов  ',
            'email' => 'supplier-private@example.test', 'tax_number' => 'supplier-private-tax-number']);
        foreach (['general_contractor_supply', 'contractor_supply', 'subcontractor_supply',
            'general_contractor_to_supplier', 'contractor_to_supplier', 'subcontractor_to_supplier'] as $type) {
            $contract = $this->contract($org, $project, '0.10');
            $contract->parties()->delete();
            DB::table('contracts')->where('id', $contract->id)->update(['contract_side_type' => $type,
                'supplier_id' => $supplier->id, 'contractor_id' => null]);
        }
        $foreign = Organization::factory()->create();
        $foreignSupplier = \App\Models\Supplier::query()->create(['organization_id' => $foreign->id, 'name' => 'Чужой поставщик']);
        $shared = $this->contract($foreign, $project, '50.00');
        $shared->parties()->delete();
        DB::table('contracts')->where('id', $shared->id)->update(['contract_side_type' => 'contractor_supply',
            'supplier_id' => $foreignSupplier->id, 'contractor_id' => null]);
        \App\Models\ContractOrganizationView::query()->updateOrCreate(
            ['contract_id' => $shared->id, 'organization_id' => $org->id], ['visibility' => 'active', 'version' => 1]);
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ], 'order_by' => [['field' => 'counterparty_name', 'direction' => 'asc']]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Поставщик материалов', 'RUB', 6, '0.60'], [null, 'RUB', 1, '50.00']], $report['sections'][0]['rows']);
        $filtered = array_replace($section, ['filters' => [['field' => 'counterparty_name', 'operator' => 'contains', 'value' => 'поставщик']]]);
        self::assertSame([['Поставщик материалов', 'RUB', 6, '0.60']], $this->builder->build($this->plan($filtered), $actor, $org->id)['sections'][0]['rows']);
        self::assertStringNotContainsString('supplier-private', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Чужой поставщик', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(2, $report['source_refs']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][1]));
        $this->denied = ['suppliers.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][1]));
        self::assertSame([[null, 'RUB', 7, '50.60']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_legacy_untyped_contract_uses_supplier_only_when_live_contractor_is_missing(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $supplier = \App\Models\Supplier::query()->create(['organization_id' => $org->id, 'name' => 'Поставщик материалов']);
        foreach (['0.10', '0.20', '0.40'] as $index => $amount) {
            $contract = $this->contract($org, $project, $amount);
            $contract->parties()->delete();
            DB::table('contracts')->where('id', $contract->id)->update(['contract_side_type' => null,
                'supplier_id' => $supplier->id, 'contractor_id' => $index === 0 ? null : $contract->contractor_id]);
            if ($index === 2) {
                $contract->contractor->delete();
            }
        }
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ], 'order_by' => [['field' => 'counterparty_name', 'direction' => 'asc']]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Подрядчик', 'RUB', 1, '0.20'], ['Поставщик материалов', 'RUB', 2, '0.50']], $report['sections'][0]['rows']);
        self::assertCount(3, $report['source_refs']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][2]));
        $this->denied = ['contractors.view'];
        self::assertSame([['Поставщик материалов', 'RUB', 2, '0.50'], [null, 'RUB', 1, '0.20']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        $this->denied = ['suppliers.view'];
        self::assertFalse($this->references->canReadReference($actor, $org->id, $report['source_refs'][2]));
        self::assertSame([['Подрядчик', 'RUB', 1, '0.20'], [null, 'RUB', 2, '0.50']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_legacy_general_contract_uses_current_project_customer_and_keeps_external_contractor(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $customer = \App\Models\Counterparty::query()->create(['organization_id' => $org->id,
            'name' => 'Заказчик проекта', 'email' => 'project-customer-private@example.test']);
        $project->update(['customer_counterparty_id' => $customer->id, 'customer' => 'Старое имя заказчика']);
        $contracts = [];
        foreach (['general_contract', 'customer_to_general_contractor'] as $index => $type) {
            $contract = $this->contract($org, $project, $index === 0 ? '0.10' : '0.20');
            $contract->parties()->delete();
            $contract->contractor->update(['source_organization_id' => $org->id]);
            DB::table('contracts')->where('id', $contract->id)->update(['contract_side_type' => $type,
                'contractor_id' => $index === 0 ? $contract->contractor_id : null]);
            $contracts[] = $contract;
        }
        $external = $this->contract($org, $project, '1.00');
        $external->parties()->delete();
        DB::table('contracts')->where('id', $external->id)->update(['contract_side_type' => 'general_contract']);
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ], 'order_by' => [['field' => 'counterparty_name', 'direction' => 'asc']]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Заказчик проекта', 'RUB', 2, '0.30'], ['Подрядчик', 'RUB', 1, '1.00']], $report['sections'][0]['rows']);
        self::assertStringNotContainsString('project-customer-private', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame([$project->id], $report['source_refs'][0]['_report_scope']['customer_project_ids']);
        $this->denied = ['contractors.view'];
        self::assertSame([['Заказчик проекта', 'RUB', 2, '0.30'], [null, 'RUB', 1, '1.00']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $this->denied = [];
        $project->update(['customer_counterparty_id' => null, 'customer' => 'Заказчик текстом']);
        $textCustomer = array_replace($section, ['filters' => [['field' => 'counterparty_name', 'operator' => 'contains', 'value' => 'текстом']]]);
        self::assertSame([['Заказчик текстом', 'RUB', 1, '0.10']], $this->builder->build($this->plan($textCustomer), $actor, $org->id)['sections'][0]['rows']);
        $project->update(['customer' => null]);
        self::assertSame([['Подрядчик', 'RUB', 1, '1.00'], [null, 'RUB', 2, '0.30']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        $registered = Organization::factory()->create(['name' => 'Заказчик из реестра']);
        app(\App\Services\Project\ProjectParticipantService::class)->attach($project, $registered->id, \App\Enums\ProjectOrganizationRole::CUSTOMER);
        $project->update(['customer_counterparty_id' => $customer->id, 'customer' => 'Старое имя заказчика']);
        foreach ([...$contracts, $external] as $contract) {
            $contract->parties()->delete();
        }
        $filtered = array_replace($section, ['filters' => [['field' => 'counterparty_name', 'operator' => 'contains', 'value' => 'из реестра']]]);
        self::assertSame([['Заказчик из реестра', 'RUB', 1, '0.10']], $this->builder->build($this->plan($filtered), $actor, $org->id)['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $single = $this->builder->build($this->plan($this->contractsSection(['columns' => ['number', 'counterparty_name'],
            'filters' => [['field' => 'number', 'operator' => 'eq', 'value' => $contracts[0]->number]]])), $actor, $org->id);
        $otherProject = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($otherProject->id, ['is_active' => true, 'role' => 'member']);
        $contracts[0]->update(['project_id' => $otherProject->id]);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertTrue($this->policy->canReadEntity($actor, $org->id, 'contract', $contracts[0]->id));
        self::assertFalse($this->references->canReadReference($actor, $org->id, $single['source_refs'][0]));
    }

    public function test_legacy_general_contract_with_missing_live_contractor_matches_project_counterparty_fallback(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $customer = \App\Models\Counterparty::query()->create(['organization_id' => $org->id, 'name' => 'Контрагент проекта']);
        $registered = Organization::factory()->create(['name' => 'Другой действующий заказчик']);
        app(\App\Services\Project\ProjectParticipantService::class)->attach($project, $registered->id, \App\Enums\ProjectOrganizationRole::CUSTOMER);
        $project->update(['customer_counterparty_id' => $customer->id, 'customer' => 'Исторический заказчик текстом']);
        foreach (['general_contract', 'customer_to_general_contractor', 'general_contract'] as $index => $type) {
            $contract = $this->contract($org, $project, '0.10');
            if ($index === 0) {
                $contract->contractor->update(['source_organization_id' => $org->id]);
            }
            if ($index < 2) {
                $contract->contractor->delete();
            }
            DB::table('contracts')->where('id', $contract->id)->update(['contract_side_type' => $type,
                'contractor_id' => $index === 2 ? null : $contract->contractor_id]);
            $contract->parties()->delete();
            self::assertSame('Контрагент проекта', app(\App\Services\Contract\ContractDossierCounterpartyResolver::class)->name($contract->refresh()));
        }
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Контрагент проекта', 'RUB', 3, '0.30']], $report['sections'][0]['rows']);
        self::assertSame([$project->id], $report['source_refs'][0]['_report_scope']['customer_project_ids']);
        $this->denied = ['contractors.view'];
        self::assertSame($report['sections'][0]['rows'], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
        $project->update(['customer_counterparty_id' => null]);
        self::assertSame([[null, 'RUB', 3, '0.30']], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
    }

    public function test_legacy_contract_with_deleted_relation_preserves_single_party_snapshot(): void
    {
        $this->extraModules = ['catalog-management'];
        [$org, $actor, $project] = $this->fixture();
        $customer = \App\Models\Counterparty::query()->create(['organization_id' => $org->id, 'name' => 'Другой контрагент проекта']);
        $project->update(['customer_counterparty_id' => $customer->id]);
        $supplier = \App\Models\Supplier::query()->create(['organization_id' => $org->id, 'name' => 'Удалённый поставщик']);
        $contracts = [];
        foreach (\App\Enums\Contract\ContractSideTypeEnum::cases() as $type) {
            $contract = $this->contract($org, $project, '0.10');
            if (! $type->requiresSupplier()) {
                $contract->contractor->delete();
            }
            DB::table('contracts')->where('id', $contract->id)->update(['contract_side_type' => $type->value,
                'contractor_id' => $type->requiresSupplier() ? null : $contract->contractor_id,
                'supplier_id' => $type->requiresSupplier() ? $supplier->id : null]);
            $contract->parties()->delete();
            $side = $type->requiresProjectCustomer() ? 'first' : 'second';
            \App\Models\ContractParty::withoutEvents(static fn () => \App\Models\ContractParty::query()->create([
                'contract_id' => $contract->id, 'side' => $side, 'party_key' => $side,
                'role' => 'contractor', 'name' => '  Сторона из снимка  ', 'snapshot' => [],
            ]));
            $contracts[] = $contract;
        }
        $supplier->delete();
        foreach ($contracts as $contract) {
            self::assertSame('Сторона из снимка', app(\App\Services\Contract\ContractDossierCounterpartyResolver::class)->name($contract->refresh()));
        }
        $section = $this->contractsSection(['columns' => ['counterparty_name', 'currency'],
            'group_by' => ['counterparty_name', 'currency'], 'metrics' => [
                ['field' => null, 'operation' => 'count', 'label' => 'Договоров'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма'],
            ]]);
        $report = $this->builder->build($this->plan($section), $actor, $org->id);
        self::assertSame([['Сторона из снимка', 'RUB', 6, '0.60']], $report['sections'][0]['rows']);
        self::assertCount(1, $report['source_refs']);
        self::assertArrayNotHasKey('customer_project_ids', $report['source_refs'][0]['_report_scope']);
        $this->denied = ['contractors.view', 'suppliers.view', 'projects.view'];
        self::assertSame($report['sections'][0]['rows'], $this->builder->build($this->plan($section), $actor, $org->id)['sections'][0]['rows']);
        self::assertTrue($this->references->canReadReference($actor, $org->id, $report['source_refs'][0]));
    }

    private function contractsSection(array $override): array
    {
        return array_replace(['domain' => 'contracts', 'entity_type' => 'contract', 'title' => 'Договоры', 'columns' => ['number']], $override);
    }

    private function contract(Organization $org, Project $project, string $amount, string $currency = 'RUB', string $date = '2026-10-01'): Contract
    {
        $contractor = Contractor::query()->create(['organization_id' => $org->id, 'name' => 'Подрядчик']);

        return Contract::query()->create(['organization_id' => $org->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'QA-'.bin2hex(random_bytes(4)), 'date' => $date, 'total_amount' => $amount, 'currency' => $currency]);
    }
}
