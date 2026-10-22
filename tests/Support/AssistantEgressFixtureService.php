<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\Models\User;
use App\Services\Logging\LoggingService;

final class AssistantEgressFixtureService extends AIAssistantService
{
    private AssistantEgressFixtureRuntime $fixture;

    public static function forScope(LLMProviderInterface $responses, User $actor, int $organizationId, ?OrdinaryAssistantScopedContext $scopedContext = null, ?RagRetriever $ragRetriever = null): self
    {
        $fixture = new AssistantEgressFixtureRuntime($organizationId, (int) $actor->getKey(), $responses, app(LoggingService::class),
            $scopedContext === null ? null : new OrdinaryAssistantEgress($scopedContext));
        $service = app()->makeWith(self::class, ['llmProvider' => $fixture->provider, 'ragRetriever' => $ragRetriever,
            'ordinaryEgress' => $fixture->egress, 'scopedContext' => $scopedContext]);
        $service->fixture = $fixture;

        return $service;
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $prepared = parent::prepareProviderPayload($messages, $options, $organizationId, $user);
        $this->fixture->approvePrepared($prepared[0], $prepared[1], $organizationId, $user);

        return $prepared;
    }
}
