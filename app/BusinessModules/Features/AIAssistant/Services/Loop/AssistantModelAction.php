<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use LogicException;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;

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

    public static function native(array $items): self
    {
        $items = GatewayModelResponse::outputItems(GatewayModelRequest::canonicalJson($items));
        foreach ($items as $item) {
            if ($item['type'] === 'function_call') {
                return new self(['type' => 'tool', 'tool' => $item['name'] === 'material_search' ? 'material.search' : 'material.read_selected',
                    'arguments' => GatewayModelResponse::functionArguments($item['name'], $item['arguments']),
                    'nativeItem' => $item]);
            }
        }
        foreach ($items as $item) {
            if ($item['type'] === 'message') { return self::parse(GatewayModelRequest::decodeJson($item['content'][0]['text'], 32768)); }
        }
        throw new LogicException('model_action_invalid');
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
