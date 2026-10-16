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

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => ! in_array($permission, $this->denied, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug],
            ['project-management', 'contract-management', 'payments', 'reports', 'ai-assistant'])));
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
