<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use LogicException;

final readonly class AssistantToolResult
{
    private function __construct(private array $envelope, private array $projection, private array $callBinding, private array $metadata, private string $tool, private array $arguments, private AuthenticatedPrivateContext $privateContext)
    {
    }

    public static function projected(array $envelope, array $projection, array $callBinding, string $tool, array $arguments, AuthenticatedPrivateContext $privateContext): self
    {
        $unitRefs = [];
        foreach ($envelope['coverage']['claimScope']['unitRefs'] as $rawRef) {
            $ref = array_search($rawRef, $projection['referenceMap'], true);
            if (!is_string($ref)) {
                throw new LogicException('tool_scope_mapping_invalid');
            }
            $unitRefs[] = $ref;
        }
        $scope = ['kind' => $envelope['coverage']['claimScope']['kind'], 'scopeRef' => self::opaqueRef(), 'sourceGenerationRef' => self::opaqueRef(), 'unitRefs' => $unitRefs];

        return new self(AssistantContextSourceBinding::detached($envelope), AssistantContextSourceBinding::detached($projection), AssistantContextSourceBinding::detached($callBinding), ['selectionRefs' => array_keys($projection['referenceMap']), 'claimScope' => $scope, 'toolKind' => $envelope['toolKind'], 'status' => $envelope['status']], $tool, AssistantContextSourceBinding::detached($arguments), $privateContext);
    }

    public static function opaqueRef(): string
    {
        return 'ref_' . bin2hex(random_bytes(16));
    }

    public function modelMetadata(): array
    {
        return AssistantContextSourceBinding::detached($this->metadata);
    }

    public function resolveSelection(string $ref): string
    {
        $rawRef = $this->projection['referenceMap'][$ref] ?? null;
        if (!is_string($rawRef)) {
            throw new LogicException('tool_reference_unavailable');
        }

        return $rawRef;
    }

    public function evidence(): array
    {
        return AssistantContextSourceBinding::detached(['envelope' => $this->envelope, 'projection' => $this->projection, 'callBinding' => $this->callBinding, 'modelMetadata' => $this->metadata]);
    }

    public function artifactRef(): string
    {
        return $this->projection['artifactRef'];
    }

    public function privateCall(): array
    {
        return ['tool' => $this->tool, 'arguments' => AssistantContextSourceBinding::detached($this->arguments)];
    }

    public function matchesPrivateContext(AuthenticatedPrivateContext $context): bool
    {
        return $context->sameSnapshot($this->privateContext);
    }

    public function __serialize(): array
    {
        throw new LogicException('tool_result_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('tool_result_deserialization_forbidden');
    }
}
