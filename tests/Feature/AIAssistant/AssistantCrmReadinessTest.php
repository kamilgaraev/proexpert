<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectCrmReader;
use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantCrmReadinessTest extends TestCase
{
    public function test_crm_uuid_reads_and_native_project_counts_preserve_scope_and_freshness(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $fixture->organization->id; $actor = $fixture->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $reader = app(AssistantProjectCrmReader::class); $args = ['project_id' => (int) $project->id];
        $company = Model::withoutEvents(fn () => \App\BusinessModules\Features\Crm\Models\CrmCompany::query()->create(['organization_id' => $org, 'name' => 'Компания']));
        Model::withoutEvents(fn () => CrmDeal::query()->create(['organization_id' => $org, 'company_id' => $company->id, 'title' => 'Не привязано к проекту', 'status' => 'open']));
        self::assertSame(0, $reader->read($actor, $org, $args)['data']['open']);
        $deal = null;
        Model::withoutEvents(function () use ($org, $actor, $project, $company, &$deal): void {
            foreach (range(1, 23) as $i) {
                $deal = CrmDeal::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'company_id' => $company->id, 'title' => 'Сделка '.$i,
                    'status' => 'open', 'owner_user_id' => $actor->id]);
            }
            CrmActivity::query()->create(['organization_id' => $org, 'deal_id' => $deal->id, 'type' => 'task', 'subject' => 'Связаться',
                'status' => 'planned', 'due_at' => now()->subDay(), 'owner_user_id' => $actor->id]);
        });
        $result = $reader->read($actor, $org, $args);
        self::assertSame(23, $result['data']['open']); self::assertCount(20, $result['data']['deals']);
        self::assertSame(1, $result['data']['activities']['total']); self::assertSame(1, $result['data']['activities']['overdue']);
        self::assertTrue(\Illuminate\Support\Str::isUuid($result['data']['deals'][0]['id']));
        self::assertTrue($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        self::assertStringNotContainsString('Не привязано к проекту', json_encode($result, JSON_UNESCAPED_UNICODE));
        $service = app(AssistantDomainReadService::class);
        $read = $service->execute('read', ['domain' => 'crm', 'entity_type' => 'crm_deal', 'id' => $deal->id, 'fields' => ['title', 'status']], $actor, $org);
        self::assertSame($deal->id, $read['results'][0]['id']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($actor, $org, $read['source_refs']));
        try { $service->execute('read', ['domain' => 'crm', 'entity_type' => 'crm_deal', 'id' => 'invalid-id', 'fields' => ['title']], $actor, $org); self::fail('Invalid CRM key must be rejected'); }
        catch (ValidationException) { self::assertTrue(true); }
        $deal->updateQuietly(['status' => 'won']); self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
    }
}
