<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class AssistantSummarySelectionTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_search_matches_remain_sources_without_becoming_selected_entities(): void
    {
        $service = $this->service();
        $sources = [['entity_type' => 'material', 'entity_id' => 1], ['entity_type' => 'material', 'entity_id' => 2]];
        (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->setValue($service, [['source_refs' => $sources]]);

        $selected = $this->selected($service, ['context' => []]);

        $this->assertSame([], $selected);
        $this->assertSame($sources, (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->getValue($service)[0]['source_refs']);
    }

    public function test_filtered_explicit_selection_and_active_estimate_survive_without_duplicate_source_fanout(): void
    {
        $service = $this->service();
        (new ReflectionProperty(AIAssistantService::class, 'activeEstimateSelection'))->setValue($service, ['estimate_id' => 31, 'position_numbers' => [4]]);
        $request = ['context' => ['entity_refs' => [['type' => 'project', 'id' => 11, 'organization_id' => 999]],
            'entity_references' => [['entity_type' => 'project', 'entity_id' => '11']]]];

        $this->assertSame([['entity_type' => 'project', 'entity_id' => 11, 'organization_id' => 15],
            ['entity_type' => 'estimate', 'entity_id' => 31, 'organization_id' => 15]], $this->selected($service, $request));
    }

    public function test_resolved_estimate_replaces_old_pin_without_selecting_other_returned_positions(): void
    {
        $service = $this->service();
        (new ReflectionProperty(AIAssistantService::class, 'activeEstimateSelection'))->setValue($service, ['estimate_id' => 31]);
        (new ReflectionProperty(AIAssistantService::class, 'estimateResolutionAttempted'))->setValue($service, true);
        (new ReflectionProperty(AIAssistantService::class, 'resolvedEstimateId'))->setValue($service, 32);
        (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->setValue($service, [['source_refs' => [
            ['entity_type' => 'estimate', 'entity_id' => 32], ['entity_type' => 'estimate_item', 'entity_id' => 44],
        ]]]);

        $this->assertSame([['entity_type' => 'estimate', 'entity_id' => 32, 'organization_id' => 15]], $this->selected($service, ['context' => []]));
        (new ReflectionProperty(AIAssistantService::class, 'resolvedEstimateId'))->setValue($service, null);
        $this->assertSame([], $this->selected($service, ['context' => []]));
    }

    public function test_current_membership_revocation_clears_resolved_selection_before_summary_without_discarding_answer_proof(): void
    {
        $service = $this->service();
        $actor = (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => false]);
        $policy = new AssistantDataAccessPolicy($this->createMock(AuthorizationService::class), $this->createMock(UserProjectAccessService::class));
        (new ReflectionProperty(AIAssistantService::class, 'dataAccess'))->setValue($service, $policy);
        (new ReflectionProperty(AIAssistantService::class, 'estimateResolutionAttempted'))->setValue($service, true);
        (new ReflectionProperty(AIAssistantService::class, 'resolvedEstimateId'))->setValue($service, 32);
        $proof = [['entity_type' => 'estimate', 'entity_id' => 32, 'organization_id' => 15]];
        (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->setValue($service, [['source_refs' => $proof]]);
        $conversation = (new Conversation)->forceFill(['id' => 7, 'organization_id' => 15]);
        $manager = $this->createMock(ConversationManager::class);
        $manager->expects($this->once())->method('updateContext')->with($conversation, $actor, 15, [], ['selected_estimate', 'selected_estimate_id'], true)->willReturn($conversation);
        (new ReflectionProperty(AIAssistantService::class, 'conversationManager'))->setValue($service, $manager);

        (new ReflectionMethod(AIAssistantService::class, 'rememberResolvedEstimate'))->invoke($service, $conversation, $actor, 15);

        $this->assertSame([], $this->selected($service, ['context' => []]));
        $this->assertFalse($policy->canReadReference($actor, 15, $proof[0]));
        $this->assertSame($proof, (new ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->getValue($service)[0]['source_refs']);
    }

    private function service(): AIAssistantService
    {
        return (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
    }

    private function selected(AIAssistantService $service, array $request): array
    {
        return (new ReflectionMethod(AIAssistantService::class, 'selectedSummaryEntities'))->invoke($service, $request, 15);
    }
}
