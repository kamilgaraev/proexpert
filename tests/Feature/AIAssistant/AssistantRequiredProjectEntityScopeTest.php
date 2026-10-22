<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantRequiredProjectEntityScopeTest extends TestCase
{
    public function test_model_selected_contract_keeps_its_identity_but_cannot_read_another_requested_project(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        app()->instance(LLMProviderInterface::class, $provider);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        [$project, $other, $ownContract, $otherContract] = Model::withoutEvents(function () use ($fixture): array {
            $project = Project::factory()->create(['organization_id' => $fixture->organization->id, 'status' => 'active', 'is_archived' => false]);
            $other = Project::factory()->create(['organization_id' => $fixture->organization->id, 'status' => 'active', 'is_archived' => false]);
            $contractor = Contractor::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Подрядчик проверки области']);
            $contract = fn (Project $parent, string $number) => Contract::query()->create(['organization_id' => $fixture->organization->id,
                'project_id' => $parent->id, 'contractor_id' => $contractor->id, 'number' => $number, 'date' => '2026-10-01', 'total_amount' => '100.00', 'currency' => 'RUB']);

            return [$project, $other, $contract($project, 'OWN-PROJECT'), $contract($other, 'OTHER-PROJECT')];
        });
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->withCurrentChecks($fixture->owner, (int) $fixture->organization->id,
            fn (): bool => $policy->canReadEntity($fixture->owner, (int) $fixture->organization->id, 'contract', $otherContract->id), true));
        $registry = app(AIToolRegistry::class);
        $definition = $registry->getTool('assistant_domain_read');
        self::assertNotNull($definition);
        $reader = app(AssistantDomainReadService::class);
        $executions = [];
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('assistant_domain_read');
        $tool->method('getParametersSchema')->willReturn($definition->getParametersSchema());
        $tool->expects($this->exactly(2))->method('execute')->willReturnCallback(static function (array $arguments, User $actor, Organization $organization) use (&$executions, $reader): array {
            $executions[] = $arguments;

            return $reader->execute('read', $arguments, $actor, (int) $organization->id);
        });
        $registry->registerTool($tool);
        $service = app(AIAssistantService::class);
        $arguments = ['domain' => 'contracts', 'entity_type' => 'contract', 'id' => (int) $otherContract->id, 'fields' => ['number'],
            'projection' => null, 'projection_offset' => 0, 'projection_limit' => 20];

        $foreign = $this->dispatch($service, $arguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $foreign['reason']);
        self::assertSame([], $foreign['source_refs']);
        self::assertSame([], $executions);
        self::assertSame([], (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->getValue($service));

        $contractorArguments = array_replace($arguments, ['domain' => 'core_catalog', 'entity_type' => 'contractor', 'id' => (int) $ownContract->contractor_id, 'fields' => ['name']]);
        $unlinked = $this->dispatch($service, $contractorArguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $unlinked['reason']);
        self::assertSame([], $executions);

        $arguments['id'] = (int) $ownContract->id;
        $arguments['fields'] = ['number', 'contractor_id'];
        $own = $this->dispatch($service, $arguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame([$arguments], $executions);
        self::assertSame((int) $ownContract->id, $own['results'][0]['id']);
        self::assertSame((int) $project->id, $own['source_refs'][0]['project_id']);

        $activeResults = new ReflectionProperty(AIAssistantService::class, 'activeToolResults');
        $checkedContract = $activeResults->getValue($service);
        $uncheckedContract = $checkedContract;
        $uncheckedContract[0]['source_refs'][0]['checked_fields'] = ['number'];
        $activeResults->setValue($service, $uncheckedContract);
        $unchecked = $this->dispatch($service, $contractorArguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $unchecked['reason']);
        self::assertSame([$arguments], $executions);

        $activeResults->setValue($service, $checkedContract);
        $party = $this->dispatch($service, $contractorArguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame((int) $ownContract->contractor_id, $party['results'][0]['id']);
        self::assertSame([$arguments, $contractorArguments], $executions);

        $otherContractor = Model::withoutEvents(fn () => Contractor::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Несвязанный подрядчик',
        ]));
        $foreignParty = $this->dispatch($service, array_replace($contractorArguments, ['id' => (int) $otherContractor->id]), $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $foreignParty['reason']);
        self::assertSame([$arguments, $contractorArguments], $executions);

        $ownContract->updateQuietly(['contractor_id' => $otherContractor->id]);
        $changedParty = $this->dispatch($service, $contractorArguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $changedParty['reason']);
        self::assertSame([$arguments, $contractorArguments], $executions);

        $ownContract->updateQuietly(['contractor_id' => $contractorArguments['id'], 'project_id' => $other->id]);
        $movedParty = $this->dispatch($service, $contractorArguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $movedParty['reason']);
        self::assertSame([$arguments, $contractorArguments], $executions);

        $relocated = $this->dispatch($service, $arguments, $project, $fixture->owner, $fixture->organization);
        self::assertSame('current_project_scope_mismatch', $relocated['reason']);
        self::assertSame([$arguments, $contractorArguments], $executions);
    }

    private function dispatch(AIAssistantService $service, array $arguments, Project $project, User $actor, Organization $organization): array
    {
        $question = 'Какой бюджет у нашего объекта? В какой валюте сумма?';
        $plan = ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $project->id]]]]];
        $call = ['name' => 'assistant_domain_read', 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        $executed = null;
        $evidence = $failures = $proposed = $urls = [];
        $result = (new ReflectionMethod($service, 'handleToolCall'))->invokeArgs($service,
            [$call, $organization, $actor, (int) $organization->id, $plan, false, &$executed, &$evidence, &$failures, &$proposed, &$urls]);
        self::assertNull($executed);
        self::assertSame([], $failures);
        self::assertSame([], $proposed);
        self::assertIsArray($result);

        return $result;
    }
}
