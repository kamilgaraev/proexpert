<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use LogicException;

final readonly class AssistantModelAction
{
    private function __construct(private array $value)
    {
    }

    public static function parse(mixed $value): self
    {
        if (!is_array($value) || !is_string($value['type'] ?? null)) {
            throw new LogicException('model_action_invalid');
        }
        $keys = match ($value['type']) {
            'plan' => ['type', 'plan'],
            'tool' => ['type', 'tool', 'arguments'],
            'refine', 'summary' => ['type', 'ref'],
            'final' => ['type', 'text', 'claims', 'sourceRefs', 'claimScope'],
            default => throw new LogicException('model_action_invalid'),
        };
        if (!AssistantModelContextProfile::hasExactKeys($value, $keys)) {
            throw new LogicException('model_action_invalid');
        }
        foreach (['plan', 'text', 'ref'] as $key) {
            if (isset($value[$key]) && (!is_string($value[$key]) || $value[$key] === '' || strlen($value[$key]) > 32768 || preg_match('//u', $value[$key]) !== 1 || str_contains($value[$key], "\0"))) {
                throw new LogicException('model_action_invalid');
            }
        }
        if ($value['type'] === 'tool') {
            if (!in_array($value['tool'], ['material.search', 'material.read_selected'], true) || !is_array($value['arguments'])) {
                throw new LogicException('tool_not_allowed');
            }
            $args = $value['arguments'];
            if ($value['tool'] === 'material.search') {
                if (!AssistantModelContextProfile::hasExactKeys($args, ['query', 'limit']) || !is_string($args['query']) || trim($args['query']) === '' || strlen($args['query']) > 512 || preg_match('//u', $args['query']) !== 1 || str_contains($args['query'], "\0") || !is_int($args['limit']) || $args['limit'] < 1 || $args['limit'] > 10) {
                    throw new LogicException('tool_arguments_invalid');
                }
            } elseif (!AssistantModelContextProfile::hasExactKeys($args, ['ref'])) {
                throw new LogicException('tool_arguments_invalid');
            } else {
                AssistantContextSourceBinding::references([$args['ref']]);
            }
        }
        if ($value['type'] === 'final') {
            if (!is_array($value['claims']) || !array_is_list($value['claims']) || count($value['claims']) > 64 || !is_array($value['claimScope'])) {
                throw new LogicException('reply_shape_invalid');
            }
            AssistantContextSourceBinding::references($value['sourceRefs']);
            foreach ($value['claims'] as $claim) {
                if (!is_array($claim) || !AssistantModelContextProfile::hasExactKeys($claim, ['value', 'unit', 'currency', 'sourceRefs']) || !is_string($claim['value']) || $claim['value'] === '' || strlen($claim['value']) > 512 || (!is_null($claim['unit']) && !is_string($claim['unit'])) || (!is_null($claim['currency']) && !is_string($claim['currency']))) {
                    throw new LogicException('reply_shape_invalid');
                }
                AssistantContextSourceBinding::references($claim['sourceRefs']);
            }
        }

        return new self(AssistantContextSourceBinding::detached($value));
    }

    public function type(): string
    {
        return $this->value['type'];
    }

    public function values(): array
    {
        return AssistantContextSourceBinding::detached($this->value);
    }
}
