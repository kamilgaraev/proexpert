<?php

declare(strict_types=1);

namespace App\Services\Contract;

use DOMDocument;
use DOMXPath;

final class ContractProposalDiff
{
    public function compare(array $before, array $after): array
    {
        $formatting = (new ContractFormattingDiff)->compare($before['document'], $after['document']);
        $changedFormattingClauses = array_filter(array_column($formatting, 'clause_id'));
        $previousImages = $this->images($before['document']);
        $nextImages = $this->images($after['document']);
        $replaced = [];
        foreach ($nextImages as $path => $source) { if (isset($previousImages[$path]) && $previousImages[$path] !== $source) { $replaced[$path] = true; } }
        $left = $this->render($before, $replaced, false);
        $right = $this->render($after, $replaced, true);
        $values = [];
        foreach (array_unique([...array_keys($before['definitions']), ...array_keys($after['definitions'])]) as $id) {
            $previous = $before['values'][$id] ?? null;
            $next = $after['values'][$id] ?? null;
            $previousField = $before['definitions'][$id] ?? null;
            $nextField = $after['definitions'][$id] ?? null;
            if ($previous !== $next || $previousField !== $nextField) {
                $values[] = ['id' => $id, 'title' => $nextField['title'] ?? $previousField['title'],
                    'before' => $previous, 'after' => $next, 'before_version' => $previousField['version'] ?? null, 'after_version' => $nextField['version'] ?? null,
                    'before_display' => $this->value($previousField['definition'] ?? [], $previous, $before['entity_snapshots'] ?? [], $before['document']['attrs']['locale'] ?? 'ru-RU'),
                    'after_display' => $this->value($nextField['definition'] ?? [], $next, $after['entity_snapshots'] ?? [], $after['document']['attrs']['locale'] ?? 'ru-RU')];
            }
        }
        $previousClauses = $this->clauses($left);
        $nextClauses = $this->clauses($right);
        $clauses = [];
        foreach (array_unique([...array_keys($previousClauses), ...array_keys($nextClauses)]) as $id) {
            $previous = $previousClauses[$id] ?? null;
            $next = $nextClauses[$id] ?? null;
            $clauses[] = ['id' => $id, 'label' => mb_substr($next ?? $previous ?? '', 0, 100),
                'before' => $previous, 'after' => $next, 'changed' => $previous !== $next || in_array($id, $changedFormattingClauses, true)];
        }
        return ['text' => ['before' => $this->text($left), 'after' => $this->text($right)], 'clauses' => $clauses, 'values' => $values,
            'formatting' => $formatting, 'related' => (new ContractRelatedContentDiff)->compare($before, $after)];
    }

    private function images(array $document): array
    {
        $result = [];
        $visit = function (array $node, string $path = '') use (&$visit, &$result): void {
            if ($node['type'] === 'image') { $result[$path] = hash('sha256', $node['attrs']['src']); }
            foreach ($node['content'] ?? [] as $index => $child) { $visit($child, $path.'.'.$index); }
        };
        $visit($document);
        return $result;
    }

    private function render(array $revision, array $replaced, bool $after): string
    {
        $document = $revision['document'];
        $text = function (array $node, string $path = '') use (&$text, $replaced, $after): array {
            if ($node['type'] === 'image') {
                $label = 'Изображение: '.($node['attrs']['alt'] ?: 'без описания').'. Размер: '.$node['attrs']['width'].' × '.$node['attrs']['height'].' мм.';
                if (isset($replaced[$path])) { $label .= $after ? ' Изображение после замены.' : ' Изображение до замены.'; }
                return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $label]]];
            }
            if (isset($node['content'])) { foreach ($node['content'] as $index => $child) { $node['content'][$index] = $text($child, $path.'.'.$index); } }
            return $node;
        };
        $document = $text($document);
        unset($document['attrs']['layout'], $document['attrs']['pageDecorations']);
        return (new ContractDocumentRenderer)->render($document, $revision['definitions'], $revision['values'], $revision['entity_snapshots'] ?? []);
    }

    private function value(array $definition, mixed $value, array $snapshots, string $locale): string
    {
        if ($value === null) { return 'Не задано'; }
        if (($definition['type'] ?? null) !== 'table') { return (new ContractDocumentRenderer)->formatValue($definition, $value, $snapshots, $locale); }
        $rows = [];
        foreach ($value as $index => $row) {
            $cells = [];
            foreach ($definition['columns'] as $column) { $cells[] = $column['label'].': '.$this->value($column['definition'], $row['values'][$column['id']] ?? null, $snapshots, $locale); }
            $rows[] = ($index + 1).'. '.implode(' | ', $cells);
        }
        return implode("\n", $rows);
    }

    private function clauses(string $html): array
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $nodes = (new DOMXPath($dom))->query('//section[starts-with(@id, "clause-")]');
        $result = [];
        foreach ($nodes ?: [] as $node) {
            if (!$node instanceof \DOMElement) { continue; }
            $result[substr($node->getAttribute('id'), 7)] = $this->text($dom->saveHTML($node) ?: '');
        }
        return $result;
    }

    private function text(string $html): string
    {
        $html = preg_replace('/<\/(?:p|h[1-6]|section|li|tr)>|<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:td|th)>/i', "\t", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n[ \t]*\n+/u', "\n", $text) ?? $text) ?? $text);
    }
}
