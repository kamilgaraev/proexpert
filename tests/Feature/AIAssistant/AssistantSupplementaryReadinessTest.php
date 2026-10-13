<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantContractSupplementaryReader;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\Contractor;
use App\Models\Project;
use App\Services\Contract\ContractOrganizationViewService;
use App\Services\Contract\ContractLibraryService;
use App\Services\Contract\ContractBuilderInstanceService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSupplementaryReadinessTest extends TestCase
{
    public function test_exact_named_scope_shared_contract_current_views_and_unavailable_are_rechecked(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'name' => 'СевГУ', 'is_archived' => false]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'name' => 'Другой объект', 'is_archived' => false]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'name' => 'СевГУ склад', 'is_archived' => false]));
        $make = static fn (int $organizationId, int $projectId, string $number): Contract => Contract::withoutEvents(fn () => Contract::query()->create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'number' => $number, 'date' => today(), 'subject' => 'Работы',
            'status' => 'draft', 'total_amount' => 100, 'is_self_execution' => true]));
        $shared = $make((int) $f->foreignOrganization->id, (int) $project->id, 'SUP-52');
        Model::withoutEvents(function () use ($shared, $f): void {
            $shared->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $f->foreignOrganization->id, 'name' => 'Владелец', 'snapshot' => []]);
            $shared->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $f->organization->id, 'name' => 'Участник', 'snapshot' => []]);
            app(ContractOrganizationViewService::class)->synchronizeNewContract($shared);
        });
        $make($org, (int) $other->id, 'SUP-52');
        $make($org, (int) $project->id, 'SUP-52-EXTRA');
        $legacy = $make($org, (int) $project->id, 'LEGACY-52');
        $reader = app(AssistantContractSupplementaryReader::class);
        $args = ['contract_number' => 'SUP-52', 'project_id' => null, 'project_query' => 'СевГУ'];
        $result = $reader->read($actor, $org, $args);
        self::assertTrue($result['data']['available']); self::assertSame((int) $shared->id, $result['data']['contract_id']);
        self::assertSame(0, $result['data']['total']); self::assertSame(0, $result['data']['pending']);
        self::assertSame((int) $project->id, $result['source_refs'][0]['project_id']); self::assertTrue($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        self::assertArrayNotHasKey('total_amount', $result['data']);
        self::assertSame((int) $shared->id, $reader->read($actor, $org, array_replace($args, ['project_query' => (string) $project->id]))['data']['contract_id']);
        $ambiguous = $reader->read($actor, $org, array_replace($args, ['project_query' => null]));
        self::assertFalse($ambiguous['data']['available']); self::assertSame('not_unique', $ambiguous['data']['reason']); self::assertArrayNotHasKey('total', $ambiguous['data']);
        $unavailable = $reader->read($actor, $org, ['contract_number' => $legacy->number, 'project_id' => null, 'project_query' => null]);
        self::assertFalse($unavailable['data']['available']); self::assertSame('native_unavailable', $unavailable['data']['reason']);
        self::assertArrayNotHasKey('total', $unavailable['data']); self::assertStringContainsString('недоступность не означает, что соглашений нет', $unavailable['server_formatted_answer']);
        $project->updateQuietly(['name' => 'Новое название']); self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        $project->updateQuietly(['name' => 'СевГУ']);
        ContractOrganizationView::query()->where('contract_id', $shared->id)->where('organization_id', $org)->update(['access_revoked_at' => now()]);
        self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        self::assertFalse($reader->read($actor, $org, $args)['data']['available']);
    }

    public function test_activation_state_is_separate_from_draft_and_private_document_values_are_not_loaded(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create(['organization_id' => $org,
            'source_organization_id' => $f->foreignOrganization->id, 'name' => 'Контрагент']));
        $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'SUP-ACTIVATION', 'date' => today(), 'status' => 'draft', 'contract_side_type' => 'subcontract', 'total_amount' => 100, 'is_self_execution' => false]));
        Model::withoutEvents(function () use ($contract, $f): void {
            $contract->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $f->organization->id, 'name' => 'Участник', 'snapshot' => []]);
            $contract->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $f->foreignOrganization->id, 'name' => 'Контрагент', 'snapshot' => []]);
            app(ContractOrganizationViewService::class)->synchronizeNewContract($contract);
        });
        $library = app(ContractLibraryService::class);
        $template = $library->create($actor, $org, 'template', 'Шаблон', ['document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Договор']]]]], 'variables' => []], 'readiness-template');
        $library->publish($actor, $org, $template['item']['id'], 1, 1);
        app(ContractBuilderInstanceService::class)->create($actor, $org, $contract->id, $template['item']['id'], 1, [], 'readiness-instance');
        $instanceId = DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('id');
        $revision = DB::table('contract_builder_revisions')->where('instance_id', $instanceId)->first(['id', 'template_version_id']);
        $document = DB::table('contract_supplementary_documents')->insertGetId(['contract_id' => $contract->id, 'instance_id' => $instanceId,
            'base_revision_id' => $revision->id, 'template_version_id' => $revision->template_version_id, 'author_organization_id' => $org, 'created_by' => $actor->id,
            'number' => 'DS-1', 'agreement_date' => today(), 'status' => 'draft', 'frame_document' => '{}', 'revision_document' => '{}',
            'revision_definitions' => '{}', 'parties' => '[{},{}]', 'base_values' => '{}', 'values' => json_encode(['secret' => str_repeat('PRIVATE_VALUE', 100000)]),
            'content_hash' => str_repeat('a', 64), 'request_key' => 'readiness-doc', 'request_fingerprint' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        $activation = DB::table('contract_supplementary_activations')->insertGetId(['document_id' => $document, 'organization_id' => $org, 'created_by' => $actor->id,
            'content_hash' => str_repeat('a', 64), 'basis' => 'Проверка чтения', 'effective_date' => today()->addDay(), 'plan' => '{}', 'previous_terms' => '{}',
            'request_key' => 'readiness-activation', 'fingerprint' => str_repeat('b', 64), 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $reader = app(AssistantContractSupplementaryReader::class); $args = ['contract_number' => $contract->number, 'project_id' => null, 'project_query' => null];
        $scheduled = $reader->read($actor, $org, $args);
        self::assertSame(1, $scheduled['data']['total']); self::assertSame(1, $scheduled['data']['pending']);
        self::assertStringContainsString('Черновик; Активация запланирована', $scheduled['server_formatted_answer']);
        self::assertStringNotContainsString('PRIVATE_VALUE', json_encode($scheduled));
        DB::table('contract_supplementary_activations')->where('id', $activation)->update(['status' => 'failed', 'last_error' => 'contracts.supplementary_not_effective']);
        self::assertFalse($reader->matchesReference($actor, $org, $scheduled['source_refs'][0]));
        $failed = $reader->read($actor, $org, $args);
        self::assertStringContainsString('Черновик; Ошибка активации', $failed['server_formatted_answer']);
        self::assertSame('draft', DB::table('contract_supplementary_documents')->where('id', $document)->value('status'));
    }
}
