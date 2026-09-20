<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Illuminate\Support\Str;

final class ContractDocumentValidator
{
    public function validate(array $content, ?array $definitions = null, bool $deferReferences = false): void
    {
        if (array_diff(array_keys($content), ['document', 'variables']) !== []
            || ! is_array($content['document'] ?? null) || ($content['document']['type'] ?? null) !== 'doc'
            || ! is_array($content['variables'] ?? null) || count($content['variables']) > 500) {
            $this->invalid();
        }
        foreach ($content['variables'] as $id => $version) {
            if (! is_string($id) || ! Str::isUuid($id) || ! is_int($version) || $version < 1) {
                $this->invalid();
            }
        }
        $count = 0;
        $clauses = [];
        $references = [];
        $this->node($content['document'], ['doc'], $content['variables'], $definitions, null, 0, $count, $clauses, $references);
        (new ContractDocumentLayoutValidator)->validate($content['document']);
        if (! $deferReferences && array_diff($references, array_keys($clauses)) !== []) {
            $this->invalid();
        }
    }

    private function node(array $node, array $allowed, array $variables, ?array $definitions, ?string $rowVariable, int $depth, int &$count, array &$clauses, array &$references): void
    {
        if ($depth > 32 || ++$count > 20000 || ! is_string($node['type'] ?? null) || ! in_array($node['type'], $allowed, true)) {
            $this->invalid();
        }
        $type = $node['type'];
        $keys = $type === 'text' ? ['type', 'text', 'marks'] : ['type', 'attrs', 'content'];
        if (array_diff(array_keys($node), $keys) !== []) {
            $this->invalid();
        }
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || mb_strlen($node['text']) > 100000) {
                $this->invalid();
            }
            $this->marks($node['marks'] ?? []);

            return;
        }
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            $this->invalid();
        }
        $attributeKeys = match ($type) {
            'heading' => ['level'], 'orderedList' => ['start'], 'clause' => ['id'],
            'clauseReference' => ['target'], 'variable' => ['variableId', 'columnId'],
            'conditional', 'repeatRows' => ['variableId'], 'tableCell' => ['colspan', 'rowspan'],
            'blockReference' => ['blockId', 'version', 'instanceId'],
            default => [],
        };
        if (in_array($type, ['doc', 'paragraph', 'heading', 'clause', 'group', 'conditional', 'table', 'orderedList', 'bulletList', 'blockReference'], true)) {
            $attributeKeys[] = 'layout';
        }
        if (array_diff(array_keys($attrs), $attributeKeys) !== []) {
            $this->invalid();
        }
        if ($type === 'blockReference' && (! is_string($attrs['blockId'] ?? null) || ! Str::isUuid($attrs['blockId'])
            || ! is_int($attrs['version'] ?? null) || $attrs['version'] < 1
            || ! is_string($attrs['instanceId'] ?? null) || preg_match('/^[A-Za-z0-9-]{1,24}$/D', $attrs['instanceId']) !== 1)) {
            $this->invalid();
        }
        if ($type === 'heading' && (! is_int($attrs['level'] ?? null) || $attrs['level'] < 1 || $attrs['level'] > 6)) {
            $this->invalid();
        }
        foreach (['start', 'colspan', 'rowspan'] as $positive) {
            if (array_key_exists($positive, $attrs) && (! is_int($attrs[$positive]) || $attrs[$positive] < 1 || $attrs[$positive] > 1000)) {
                $this->invalid();
            }
        }
        if ($type === 'clause' || $type === 'clauseReference') {
            $id = $attrs[$type === 'clause' ? 'id' : 'target'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $id) !== 1) {
                $this->invalid();
            }
            if ($type === 'clause') {
                if (isset($clauses[$id])) {
                    $this->invalid();
                }
                $clauses[$id] = true;
            } else {
                $references[] = $id;
            }
        }
        if (in_array($type, ['variable', 'conditional', 'repeatRows'], true)) {
            $id = $attrs['variableId'] ?? null;
            if (! is_string($id) || ! array_key_exists($id, $variables)) {
                $this->invalid();
            }
            $definition = $definitions[$id] ?? null;
            if ($definitions !== null && ! is_array($definition)) {
                $this->invalid();
            }
            if ($type === 'conditional' && $definition !== null && ($definition['type'] ?? null) !== 'boolean') {
                $this->invalid();
            }
            if ($type === 'repeatRows') {
                if ($rowVariable !== null || ($definition !== null && ($definition['type'] ?? null) !== 'table')) {
                    $this->invalid();
                }
                $rowVariable = $id;
            }
            if (array_key_exists('columnId', $attrs)) {
                if ($rowVariable !== $id || ! is_string($attrs['columnId'])
                    || ($definition !== null && ! in_array($attrs['columnId'], array_column($definition['columns'] ?? [], 'id'), true))) {
                    $this->invalid();
                }
            }
        }
        $block = ['paragraph', 'heading', 'clause', 'group', 'conditional', 'table', 'orderedList', 'bulletList', 'blockReference'];
        $inline = ['text', 'variable', 'clauseReference', 'hardBreak'];
        $childrenAllowed = match ($type) {
            'doc', 'clause', 'group', 'conditional', 'listItem', 'tableCell' => $block,
            'paragraph', 'heading' => $inline,
            'orderedList', 'bulletList' => ['listItem'],
            'table' => ['tableRow', 'repeatRows'], 'repeatRows' => ['tableRow'], 'tableRow' => ['tableCell'],
            default => [],
        };
        $children = $node['content'] ?? [];
        if (! is_array($children) || ! array_is_list($children) || ($childrenAllowed === [] && $children !== [])
            || (in_array($type, ['table', 'tableRow', 'repeatRows', 'orderedList', 'bulletList'], true) && $children === [])) {
            $this->invalid();
        }
        foreach ($children as $child) {
            if (! is_array($child)) {
                $this->invalid();
            }
            $this->node($child, $childrenAllowed, $variables, $definitions, $rowVariable, $depth + 1, $count, $clauses, $references);
        }
    }

    private function marks(mixed $marks): void
    {
        if (! is_array($marks) || ! array_is_list($marks) || count($marks) > 5) {
            $this->invalid();
        }
        $seen = [];
        foreach ($marks as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null)
                || ! in_array($mark['type'], ['bold', 'italic', 'underline', 'strike', 'link'], true)
                || isset($seen[$mark['type']]) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
                $this->invalid();
            }
            $seen[$mark['type']] = true;
            $attrs = $mark['attrs'] ?? [];
            if (! is_array($attrs)) {
                $this->invalid();
            }
            if ($mark['type'] === 'link') {
                $href = $attrs['href'] ?? null;
                if (array_keys($attrs) !== ['href'] || ! is_string($href) || strlen($href) > 2048
                    || preg_match('/[\x00-\x20\x7f]/', $href) === 1
                    || ! in_array(strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['https', 'http', 'mailto'], true)) {
                    $this->invalid();
                }
            } elseif ($attrs !== []) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_document_invalid', 422);
    }
}
