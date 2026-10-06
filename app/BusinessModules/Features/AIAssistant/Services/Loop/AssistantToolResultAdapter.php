<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantSafeContextSegment;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchResult;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use Closure;
use LogicException;

final readonly class AssistantToolResultAdapter
{
    public function __construct(private ?Closure $execute = null, private ?Closure $project = null, private ?Closure $privateGate = null)
    {
    }

    public function available(): bool
    {
        return $this->execute !== null && $this->project !== null && $this->privateGate !== null;
    }

    public function revalidateLatest(AssistantToolResult $result, AssistantContextReceipt $receipt): void
    {
        if ($this->privateGate === null) {
            throw new LogicException('tool_private_binding_unavailable');
        }
        $call = $result->privateCall();
        $context = ($this->privateGate)($receipt->privateBinding(), $call['tool'], $call['arguments'], $result->evidence());
        if (!$context instanceof AuthenticatedPrivateContext || !$result->matchesPrivateContext($context)) {
            throw new LogicException('tool_private_binding_changed');
        }
    }

    public function adapt(string $tool, array $arguments, AssistantContextReceipt $receipt, string $callRef, Closure $fresh): AssistantToolResult
    {
        if (!$this->available() || !in_array($tool, ['material.search', 'material.read_selected'], true)) {
            throw new LogicException('tool_not_available');
        }
        $binding = $receipt->callBinding($callRef);
        $fresh(false);
        $privateContext = ($this->privateGate)($receipt->privateBinding(), $tool, AssistantContextSourceBinding::detached($arguments));
        $fresh(false);
        if (!$privateContext instanceof AuthenticatedPrivateContext) {
            throw new LogicException('tool_private_binding_unavailable');
        }
        $fresh(false);
        $result = ($this->execute)($tool, AssistantContextSourceBinding::detached($arguments), $privateContext);
        $fresh(false);
        if (!$result instanceof MaterialSearchResult) {
            throw new LogicException('tool_result_unsealed');
        }
        $currentPrivate = ($this->privateGate)($receipt->privateBinding(), $tool, AssistantContextSourceBinding::detached($arguments));
        $fresh(false);
        if (!$currentPrivate instanceof AuthenticatedPrivateContext || !$currentPrivate->sameSnapshot($privateContext)) {
            throw new LogicException('tool_private_binding_changed');
        }
        $envelope = AssistantContextSourceBinding::detached($result->localEnvelope());
        if (!AssistantModelContextProfile::hasExactKeys($envelope, ['schemaVersion', 'requestRef', 'profileRef', 'profileVersion', 'toolKind', 'status', 'reason', 'facts', 'coverage', 'nextSafeRefs', 'resultGenerationRef']) || $envelope['schemaVersion'] !== 'safe-tool-result/1' || $envelope['toolKind'] !== ($tool === 'material.search' ? 'search' : 'read_selected') || !in_array($envelope['status'], ['verified', 'partial', 'no_data'], true) || !is_array($envelope['coverage']) || !is_array($envelope['facts'])) {
            throw new LogicException('tool_result_blocked');
        }
        $scope = $envelope['coverage']['claimScope'] ?? null;
        if (!is_array($scope) || !AssistantModelContextProfile::hasExactKeys($scope, ['kind', 'scopeRef', 'sourceGenerationRef', 'unitRefs']) || !in_array($scope['kind'], ['selected_entity', 'search_subset'], true) || $scope['sourceGenerationRef'] !== $envelope['resultGenerationRef']) {
            throw new LogicException('tool_scope_invalid');
        }
        AssistantContextSourceBinding::references($envelope['nextSafeRefs']);
        AssistantContextSourceBinding::references($scope['unitRefs']);
        $fresh(false);
        $projection = ($this->project)(AssistantContextSourceBinding::detached($envelope), AssistantContextSourceBinding::detached($binding), $receipt->privateBinding());
        $advanced = $fresh(true);
        if (!$advanced instanceof AssistantContextReceipt || !is_array($projection) || !AssistantModelContextProfile::hasExactKeys($projection, ['artifactRef', 'requestRef', 'profileRef', 'profileVersion', 'generationRef', 'referenceMap'])) {
            throw new LogicException('tool_projection_unavailable');
        }
        foreach (['requestRef', 'profileRef', 'profileVersion', 'generationRef'] as $key) {
            $rawKey = $key === 'generationRef' ? 'resultGenerationRef' : $key;
            if ($projection[$key] !== $envelope[$rawKey]) {
                throw new LogicException('tool_namespace_binding_invalid');
            }
        }
        if (in_array($envelope['requestRef'], array_values($binding), true) || in_array($envelope['profileRef'], array_values($binding), true) || !is_array($projection['referenceMap'])) {
            throw new LogicException('tool_namespace_collision');
        }
        $rawRefs = array_values($projection['referenceMap']);
        $expectedRefs = $envelope['nextSafeRefs'];
        AssistantContextSourceBinding::references($rawRefs);
        AssistantContextSourceBinding::references(array_keys($projection['referenceMap']));
        $contextAliases = array_keys($receipt->privateBinding()['receipt']['aliases']);
        foreach (array_keys($projection['referenceMap']) as $alias) {
            if (preg_match('/\Aref_[a-f0-9]{32}\z/D', $alias) !== 1 || in_array($alias, $expectedRefs, true) || in_array($alias, array_values($binding), true) || in_array($alias, $contextAliases, true) || in_array($alias, $receipt->sourceRefs(), true)) {
                throw new LogicException('tool_reference_mapping_invalid');
            }
        }
        sort($rawRefs);
        sort($expectedRefs);
        if ($rawRefs !== $expectedRefs) {
            throw new LogicException('tool_reference_mapping_invalid');
        }
        $old = $receipt->privateBinding()['snapshot'];
        $state = $advanced->privateBinding();
        if ($state['snapshot']['conversation']['historyRefs'] !== [...$old['conversation']['historyRefs'], $projection['artifactRef']]) {
            throw new LogicException('tool_chronology_invalid');
        }
        $artifact = $state['artifacts'][$projection['artifactRef']] ?? null;
        $segment = AssistantSafeContextSegment::project($projection['artifactRef'], $state['snapshot'], static fn (string $ref, array $snapshot): mixed => $artifact);
        if ($segment->kind() !== 'tool') {
            throw new LogicException('tool_artifact_kind_invalid');
        }

        return AssistantToolResult::projected($envelope, AssistantContextSourceBinding::detached($projection), $binding, $tool, $arguments, $privateContext);
    }
}
