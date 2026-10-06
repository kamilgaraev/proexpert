<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use Closure;
use LogicException;

final readonly class AssistantLoopResponseValidator
{
    public function __construct(private ?Closure $trustedValidator = null)
    {
    }

    public function available(): bool
    {
        return $this->trustedValidator !== null;
    }

    public function validate(AssistantModelAction $action, AssistantContextReceipt $receipt, array $results, Closure $fresh): array
    {
        if ($action->type() !== 'final' || !$this->available()) {
            throw new LogicException('reply_validator_unavailable');
        }
        $value = $action->values();
        if ($value['claimScope'] === $receipt->contextScope()) {
            if ($value['claims'] !== [] || $value['sourceRefs'] === [] || array_diff($value['sourceRefs'], $receipt->contextSourceRefs()) !== []) {
                return ['status' => 'repair', 'reason' => 'provenance_invalid'];
            }

            return $this->semanticVerdict($value, $receipt, $results, $fresh);
        }
        $latest = $results === [] ? null : $results[array_key_last($results)];
        if (!$latest instanceof AssistantToolResult || $value['claimScope'] !== $latest->modelMetadata()['claimScope']) {
            return ['status' => 'repair', 'reason' => 'scope_invalid'];
        }
        $evidence = $latest->evidence();
        if (!in_array($evidence['envelope']['coverage']['claimScope']['kind'], ['selected_entity', 'search_subset'], true)) {
            return ['status' => 'repair', 'reason' => 'scope_invalid'];
        }
        $toolSources = [];
        foreach ($receipt->privateBinding()['receipt']['aliases'] as $alias) {
            if ($alias['artifactRef'] === $latest->artifactRef() && $alias['kind'] === 'tool') {
                $toolSources = $alias['sourceRefs'];
            }
        }
        if ($value['sourceRefs'] === [] || array_diff($value['sourceRefs'], $toolSources) !== [] || array_diff($value['sourceRefs'], $receipt->sourceRefs()) !== []) {
            return ['status' => 'repair', 'reason' => 'provenance_invalid'];
        }
        $claimedSources = [];
        foreach ($value['claims'] as $claim) {
            if ($claim['sourceRefs'] === [] || array_diff($claim['sourceRefs'], $value['sourceRefs']) !== []) {
                return ['status' => 'repair', 'reason' => 'provenance_invalid'];
            }
            $claimedSources = [...$claimedSources, ...$claim['sourceRefs']];
            if ($claim['currency'] === null && preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $claim['value']) === 1) {
                return ['status' => 'repair', 'reason' => 'claims_invalid'];
            }
            if ($claim['currency'] !== null) {
                $found = false;
                foreach ($evidence['envelope']['facts'] as $fact) {
                    if (($fact['kind'] ?? null) === 'price' && ($fact['decimal'] ?? null) === $claim['value'] && ($fact['currency'] ?? null) === $claim['currency'] && ($fact['perUnit'] ?? null) === $claim['unit'] && ($fact['provenance']['sourceGenerationRef'] ?? null) === $evidence['envelope']['resultGenerationRef'] && in_array($fact['provenance']['unitRef'] ?? null, $evidence['envelope']['coverage']['claimScope']['unitRefs'], true)) {
                        $found = true;
                    }
                }
                if (!$found) {
                    return ['status' => 'repair', 'reason' => 'claims_invalid'];
                }
            }
        }
        $claimedSources = array_values(array_unique($claimedSources));
        if ($value['claims'] !== [] && (count($claimedSources) !== count($value['sourceRefs']) || array_diff($value['sourceRefs'], $claimedSources) !== [])) {
            return ['status' => 'repair', 'reason' => 'provenance_invalid'];
        }
        return $this->semanticVerdict($value, $receipt, $results, $fresh);
    }

    private function semanticVerdict(array $value, AssistantContextReceipt $receipt, array $results, Closure $fresh): array
    {
        $fresh(false);
        $allEvidence = array_map(static fn (AssistantToolResult $result): array => $result->evidence(), $results);
        $verdict = ($this->trustedValidator)(AssistantContextSourceBinding::detached($value), $receipt->payload(), $allEvidence);
        $fresh(false);
        if (!is_array($verdict) || !AssistantModelContextProfile::hasExactKeys($verdict, ['status', 'reason']) || !in_array($verdict['status'], ['valid', 'repair', 'blocked'], true) || !in_array($verdict['reason'], ['none', 'claims_invalid', 'pii', 'scope_invalid', 'provenance_invalid'], true) || ($verdict['status'] === 'valid' && $verdict['reason'] !== 'none')) {
            throw new LogicException('reply_validator_invalid');
        }

        return $verdict;
    }
}
