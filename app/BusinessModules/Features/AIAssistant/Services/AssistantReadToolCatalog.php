<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use InvalidArgumentException;

final readonly class AssistantReadToolCatalog
{
    public const MAX_SELECTED = 3;

    public const MAX_PAGE = 6;

    public const MAX_INDEX = 128;

    private const MAX_SCAN = 24;

    public function __construct(
        private AIToolRegistry $registry,
        private AssistantToolEligibilityPolicy $eligibility,
    ) {}

    public static function enabled(): bool
    {
        return app()->bound('config') && config('ai-assistant.read_tool_discovery_enabled', false) === true;
    }

    public static function selection(array $registered, mixed $requested, AssistantToolEligibilityPolicy $eligibility): array
    {
        if (! is_array($requested) || ! array_is_list($requested) || count($requested) > self::MAX_SELECTED) {
            return [];
        }

        return array_values(array_unique(array_filter($requested, static fn (mixed $name): bool =>
            is_string($name) && in_array($name, $registered, true) && $eligibility->isReadOnlyTool($name))));
    }

    public function describe(array $arguments, callable $allowed): array
    {
        $offset = $arguments['read_tool_offset'] ?? 0;
        $limit = $arguments['read_tool_limit'] ?? self::MAX_PAGE;
        $requested = $arguments['read_tool_names'] ?? null;
        if (! is_int($offset) || $offset < 0 || $offset > 10000
            || ! is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE) {
            throw new InvalidArgumentException('invalid_read_tool_page');
        }
        if ($requested !== null && (! is_array($requested) || ! array_is_list($requested)
            || count($requested) > self::MAX_SELECTED || array_filter($requested, static fn (mixed $name): bool =>
                ! is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $name) !== 1) !== [])) {
            throw new InvalidArgumentException('invalid_read_tool_selection');
        }

        $registered = $this->registry->getToolNames();
        $names = array_values(array_filter($registered, fn (string $name): bool => $this->eligibility->isReadOnlyTool($name)));
        $rows = [];
        $next = $offset;
        foreach (array_slice($names, $offset, self::MAX_SCAN) as $name) {
            $next++;
            if (! $allowed($name)) {
                continue;
            }
            $tool = $this->registry->getTool($name);
            if ($tool !== null) {
                $rows[] = ['name' => $name, 'description' => mb_substr($tool->getDescription(), 0, 240), 'effect' => 'read'];
            }
            if (count($rows) === $limit) {
                break;
            }
        }

        $result = ['read_tools' => $rows, 'next_read_tool_offset' => $next < count($names) ? $next : null];
        if ($requested !== null) {
            $result['selected_read_tools'] = array_values(array_filter(
                self::selection($registered, $requested, $this->eligibility), $allowed));
        }

        return $result;
    }

    public function index(callable $allowed, int $limit = self::MAX_INDEX): array
    {
        if ($limit < 1 || $limit > self::MAX_INDEX) {
            throw new InvalidArgumentException('invalid_read_tool_index_limit');
        }
        $names = array_values(array_filter($this->registry->getToolNames(),
            fn (string $name): bool => $this->eligibility->isReadOnlyTool($name)));

        return ['names' => array_values(array_filter(array_slice($names, 0, $limit), $allowed)),
            'next_read_tool_offset' => count($names) > $limit ? $limit : null];
    }
}
