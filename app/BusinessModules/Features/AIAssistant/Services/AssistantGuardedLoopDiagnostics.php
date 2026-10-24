<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Throwable;

final class AssistantGuardedLoopDiagnostics
{
    private const TOOLS = ['get_project_attention', 'get_project_production_schedule_summary', 'search_assistant_documents'];

    private const MAX_OBSERVATIONS = 16;

    private readonly string $key;

    private ?int $providerOrdinal = null;

    private int $providerCount = 0;

    private int $toolOrdinal = 0;

    private bool $complete = true;

    private array $outputs = [];

    private array $queries = [];

    private array $results = [];

    public function __construct(public readonly string $requestId)
    {
        $this->key = random_bytes(32);
    }

    public function sdkEntry(array $messages): array
    {
        try {
            $ordinal = ++$this->providerCount;
            $this->providerOrdinal = $ordinal <= self::MAX_OBSERVATIONS ? $ordinal : null;
            if ($this->providerOrdinal === null) {
                $this->complete = false;
            }
            if ($this->providerOrdinal === null || ! $this->complete || count($messages) > 64) {
                return $this->unknownInput();
            }
            $present = [];
            foreach ($messages as $message) {
                if (! is_array($message)) {
                    return $this->unknownInput();
                }
                if (($message['type'] ?? null) !== 'function_call_output') {
                    continue;
                }
                $id = $message['call_id'] ?? null;
                $output = $message['output'] ?? null;
                if (! is_string($id) || $id === '' || strlen($id) > 256
                    || ! is_string($output) || strlen($output) > 65536) {
                    return $this->unknownInput();
                }
                $identity = $this->digest($id);
                if (isset($present[$identity])) {
                    return $this->unknownInput();
                }
                $present[$identity] = $this->digest($output);
            }
            if (array_diff_key($present, $this->outputs) !== []) {
                return $this->unknownInput();
            }
            $included = 0;
            foreach ($this->outputs as $identity => $output) {
                if (isset($present[$identity]) && hash_equals($output, $present[$identity])) {
                    $included++;
                }
            }
            $expected = count($this->outputs);

            return ['provider_ordinal' => $this->providerOrdinal, 'canonical_outputs_expected' => $expected, 'canonical_outputs_in_sdk_input' => $included,
                'all_canonical_outputs_in_sdk_input' => $expected === 0 ? null : $included === $expected];
        } catch (Throwable) {
            return $this->unknownInput();
        }
    }

    public function toolResult(string $tool, array|string $arguments, array|string $result, string $callId, string $output, array $publishedTools): ?array
    {
        try {
            if (! in_array($tool, self::TOOLS, true) || ! in_array($tool, $publishedTools, true)) {
                $this->complete = false;
                return null;
            }
            if (++$this->toolOrdinal > self::MAX_OBSERVATIONS) {
                $this->complete = false;
                return null;
            }
            $valid = $this->providerOrdinal !== null && $callId !== '' && strlen($callId) <= 256 && strlen($output) <= 65536;
            if ($valid) {
                $identity = $this->digest($callId);
                if (isset($this->outputs[$identity])) {
                    $valid = false;
                } else {
                    $this->outputs[$identity] = $this->digest($output);
                }
            }
            if (! $valid) {
                $this->complete = false;
            }
            $document = $tool === 'search_assistant_documents';
            $stats = $document ? self::documentStats($result) : self::unknownDocument();
            $projected = $valid && is_array($result) && ($result['status'] ?? null) === 'success'
                && (! $document || $stats['fragment_count'] !== null);
            $repeatQuery = null;
            if (is_string($arguments)) {
                $arguments = strlen($arguments) <= 4096 ? json_decode($arguments, true) : null;
            }
            $query = is_array($arguments) ? ($arguments['query'] ?? null) : null;
            if ($projected && $document && is_string($query) && $query !== '' && strlen($query) <= 800) {
                $digest = $this->digest($query);
                $repeatQuery = isset($this->queries[$tool]) ? hash_equals($this->queries[$tool], $digest) : null;
                $this->queries[$tool] = $digest;
            }
            $repeatResult = null;
            if ($projected) {
                $digest = $this->digest($output);
                $repeatResult = isset($this->results[$tool]) ? hash_equals($this->results[$tool], $digest) : null;
                $this->results[$tool] = $digest;
            }

            return ['provider_ordinal' => $this->providerOrdinal, 'tool_ordinal' => $this->toolOrdinal, 'tool' => $tool,
                'observation_state' => $projected ? 'projected' : 'unknown',
                ...($projected ? $stats : self::unknownDocument()),
                'repeat_query' => $repeatQuery, 'repeat_result' => $repeatResult];
        } catch (Throwable) {
            $this->complete = false;
            return null;
        }
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    private static function documentStats(array|string $result): array
    {
        $data = is_array($result) ? ($result['data'] ?? null) : null;
        $fragments = is_array($data) ? ($data['fragments'] ?? null) : null;
        if (! is_array($fragments) || ! array_is_list($fragments) || count($fragments) > 3
            || ($data['kind'] ?? null) !== 'untrusted_search_results') {
            return self::unknownDocument();
        }
        $bytes = 0;
        $truncated = false;
        foreach ($fragments as $fragment) {
            if (! is_array($fragment) || ! is_string($fragment['content'] ?? null)
                || ! is_array($fragment['content_range'] ?? null) || ! is_bool($fragment['content_range']['truncated'] ?? null)) {
                return self::unknownDocument();
            }
            $bytes += strlen($fragment['content']);
            $truncated = $truncated || $fragment['content_range']['truncated'];
            if ($bytes > 65536) {
                return self::unknownDocument();
            }
        }

        return ['fragment_count' => count($fragments), 'selected_content_bytes' => $bytes,
            'empty' => $fragments === [], 'content_truncated' => $truncated];
    }

    private static function unknownDocument(): array
    {
        return ['fragment_count' => null, 'selected_content_bytes' => null, 'empty' => null, 'content_truncated' => null];
    }

    private function unknownInput(): array
    {
        return ['provider_ordinal' => $this->providerOrdinal, 'canonical_outputs_expected' => null, 'canonical_outputs_in_sdk_input' => null, 'all_canonical_outputs_in_sdk_input' => null];
    }
}
