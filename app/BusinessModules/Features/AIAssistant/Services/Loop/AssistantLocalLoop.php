<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextPreparationService;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextTokenCounter;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use Closure;
use LogicException;
use Throwable;

final readonly class AssistantLocalLoop
{
    public function __construct(
        private ?AssistantContextPreparationService $contextPreparation = null,
        private ?Closure $authority = null,
        private ?Closure $tokenizer = null,
        private ?Closure $modelDriver = null,
        private ?AssistantToolResultAdapter $toolAdapter = null,
        private ?AssistantLoopResponseValidator $responseValidator = null,
        private ?AssistantLoopLimits $limits = null,
        private ?Closure $clock = null,
        private ?Closure $finalGuard = null,
    ) {
    }

    public function run(string $profileRef, array $request): array
    {
        $trace = new AssistantLoopTrace();
        if ($this->contextPreparation === null || $this->authority === null || $this->tokenizer === null || $this->modelDriver === null || $this->toolAdapter === null || !$this->toolAdapter->available() || $this->responseValidator === null || !$this->responseValidator->available() || $this->finalGuard === null) {
            return $this->blocked('required_stage_unavailable', $trace);
        }
        try {
            return $this->runGuarded($profileRef, AssistantContextSourceBinding::detached($request), $trace);
        } catch (Throwable) {
            return $this->blocked('loop_guard_blocked', $trace);
        }
    }

    private function blocked(string $reason, AssistantLoopTrace $trace): array
    {
        $trace->add('blocked', count($trace->events()), 0);

        return ['status' => 'BLOCKED', 'reason' => $reason, 'mode' => 'offline-synthetic', 'transportAllowed' => false, 'trace' => $trace->events()];
    }

    private function authority(?string $contextRef): array
    {
        $value = ($this->authority)($contextRef);
        if (!is_array($value)) {
            throw new LogicException('authority_unavailable');
        }

        return AssistantContextSourceBinding::detached($value);
    }

    private function now(): int
    {
        $value = $this->clock === null ? intdiv(hrtime(true), 1000000) : ($this->clock)();
        if (!is_int($value) || $value < 0) {
            throw new LogicException('loop_clock_invalid');
        }

        return $value;
    }

    private function prepare(string $profileRef, array $request, ?AssistantContextReceipt $previous = null, bool $advance = false): AssistantContextReceipt
    {
        if ($previous !== null) {
            $state = $this->authority($previous->payload()['contextRef']);
            $previous->revalidate($state, $advance);
            $conversation = $state['snapshot']['conversation'];
            $request = array_intersect_key($request, array_flip(['summaryRef', 'taskFrameRef'])) + ['currentRef' => $conversation['currentRef'], 'systemRefs' => $conversation['systemRefs'], 'historyRefs' => $conversation['historyRefs'], 'mediaRefs' => $conversation['mediaRefs']];
        }
        $prepared = $this->contextPreparation->prepare($profileRef, $request);
        $contextRef = $prepared['payload']['contextRef'] ?? null;
        if (!is_string($contextRef)) {
            throw new LogicException('context_not_ready');
        }
        $next = AssistantContextReceipt::consume($prepared, $this->authority($contextRef), $profileRef);
        if ($previous !== null) {
            $old = $previous->privateBinding();
            $new = $next->privateBinding();
            if ($old['receipt']['lineage'] !== $new['receipt']['lineage'] || $old['receipt']['profileFingerprint'] !== $new['receipt']['profileFingerprint'] || $old['snapshot']['scope'] !== $new['snapshot']['scope'] || $old['snapshot']['conversation']['currentRef'] !== $new['snapshot']['conversation']['currentRef']) {
                throw new LogicException('context_refresh_changed');
            }
        }

        return $next;
    }

    private function materialRevalidate(array $results, AssistantContextReceipt $receipt, bool $advance): AssistantContextReceipt
    {
        $current = $receipt->revalidate($this->authority($receipt->payload()['contextRef']), $advance);
        foreach ($results as $result) {
            if (!$result instanceof AssistantToolResult) {
                throw new LogicException('tool_result_unsealed');
            }
            $this->toolAdapter->revalidateLatest($result, $current);
            $current = $receipt->revalidate($this->authority($receipt->payload()['contextRef']), $advance);
        }

        return $current;
    }

    private function runGuarded(string $profileRef, array $request, AssistantLoopTrace $trace): array
    {
        $limits = $this->limits ?? new AssistantLoopLimits();
        $start = $this->now();
        $lastNow = $start;
        $receipt = $this->prepare($profileRef, $request);
        $latest = null;
        $results = [];
        $fresh = function (bool $advance = false) use (&$receipt, &$results, $start, $limits, &$lastNow): AssistantContextReceipt {
            $current = $this->materialRevalidate($results, $receipt, $advance);
            $now = $this->now();
            $current = $receipt->revalidate($this->authority($receipt->payload()['contextRef']), $advance);
            if ($now < $lastNow || $now - $start >= $limits->milliseconds) {
                throw new LogicException('loop_time_limit');
            }
            $lastNow = $now;

            return $current;
        };
        $checkTime = static function () use ($fresh): void {
            $fresh(false);
        };
        $tokens = 0;
        $calls = 0;
        $repairs = 0;
        $repair = null;
        $tools = [
            ['name' => 'material.search', 'arguments' => ['query' => 'string', 'limit' => 'integer:1..10'], 'effect' => 'read'],
            ['name' => 'material.read_selected', 'arguments' => ['ref' => 'current-selection-reference'], 'effect' => 'read'],
        ];
        for ($step = 1; $step <= $limits->steps; $step++) {
            $checkTime();
            $profile = $receipt->profile();
            $counter = new AssistantContextTokenCounter($profile, $this->tokenizer);
            $input = ['schemaVersion' => 'assistant-loop-input/1', 'context' => $receipt->payload(), 'contextScope' => $receipt->contextScope(), 'tools' => $tools, 'toolReferences' => $latest?->modelMetadata(), 'repair' => $repair];
            $fresh(false);
            $inputTokens = $counter->count($input);
            $fresh(false);
            if ($inputTokens > $profile->inputBudget() || $inputTokens + $profile->modelPayload()['maxOutputTokens'] > $limits->totalTokens - $tokens) {
                throw new LogicException('loop_token_limit');
            }
            $tokens += $inputTokens;
            $checkTime();
            $fresh(false);
            $output = ($this->modelDriver)(AssistantContextSourceBinding::detached($input));
            if (!is_array($output) && !is_string($output)) {
                throw new LogicException('model_output_invalid');
            }
            $output = is_array($output) ? AssistantContextSourceBinding::detached($output) : $output;
            $fresh(false);
            $outputTokens = $counter->count(['output' => $output]);
            $fresh(false);
            $tokens += $outputTokens;
            if ($outputTokens > $profile->modelPayload()['maxOutputTokens'] || $tokens > $limits->totalTokens) {
                throw new LogicException('loop_token_limit');
            }
            $checkTime();
            $action = AssistantModelAction::parse($output);
            $value = $action->values();
            $trace->add($action->type(), $step, $tokens);
            if ($action->type() === 'plan') {
                continue;
            }
            if (in_array($action->type(), ['refine', 'summary'], true)) {
                $fresh(false);
                $alias = $receipt->resolve($value['ref'], [$action->type() === 'refine' ? 'frame' : 'summary']);
                $fresh(false);
                $request[$action->type() === 'refine' ? 'taskFrameRef' : 'summaryRef'] = $alias['artifactRef'];
                $receipt = $this->prepare($profileRef, $request, $receipt);
                continue;
            }
            if ($action->type() === 'tool') {
                if (++$calls > $limits->toolCalls) {
                    throw new LogicException('loop_tool_limit');
                }
                $arguments = $value['arguments'];
                if ($value['tool'] === 'material.read_selected') {
                    $fresh(false);
                    if (!$latest instanceof AssistantToolResult) {
                        throw new LogicException('tool_reference_unavailable');
                    }
                    $arguments['ref'] = $latest->resolveSelection($arguments['ref']);
                    $fresh(false);
                }
                $callRef = AssistantToolResult::opaqueRef();
                $trace->add('tool', $step, $tokens, $callRef);
                $latest = $this->toolAdapter->adapt($value['tool'], $arguments, $receipt, $callRef, $fresh);
                $results[] = $latest;
                $receipt = $this->prepare($profileRef, $request, $receipt, true);
                $repair = null;
                continue;
            }
            $verdict = $this->responseValidator->validate($action, $receipt, $results, $fresh);
            $checkTime();
            if ($verdict['status'] === 'valid') {
                $fresh(false);
                $conditions = ['startedAt' => $start, 'deadline' => $start + $limits->milliseconds, 'totalTokens' => $tokens, 'maxTokens' => $limits->totalTokens];
                $evidence = array_map(static fn (AssistantToolResult $result): array => $result->evidence(), $results);
                $guard = ($this->finalGuard)($receipt->privateBinding(), $conditions, $evidence);
                if (!is_array($guard) || !AssistantModelContextProfile::hasExactKeys($guard, ['authority', 'now', 'privateContext']) || !is_array($guard['authority']) || !is_int($guard['now']) || $guard['now'] < $lastNow || $guard['now'] >= $conditions['deadline'] || !$guard['privateContext'] instanceof AuthenticatedPrivateContext) {
                    throw new LogicException('reply_final_guard_invalid');
                }
                $receipt->revalidate(AssistantContextSourceBinding::detached($guard['authority']));
                foreach ($results as $result) {
                    if (!$result->matchesPrivateContext($guard['privateContext'])) {
                        throw new LogicException('reply_final_private_context_changed');
                    }
                }
                $trace->add('ready', $step, $tokens);

                return ['status' => 'READY', 'mode' => 'offline-synthetic', 'transportAllowed' => false, 'reply' => $value['text'], 'trace' => $trace->events()];
            }
            if ($verdict['status'] === 'blocked' || ++$repairs > $limits->repairs) {
                throw new LogicException('reply_validation_blocked');
            }
            $trace->add('repair', $step, $tokens);
            $repair = $verdict['reason'];
        }

        throw new LogicException('loop_step_limit');
    }
}
