<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractDocumentRenderer
{
    public function render(array $document, array $definitions, array $values, array $entitySnapshots = []): string
    {
        $types = array_column($definitions, 'definition', 'id');
        $versions = array_column($definitions, 'version', 'id');
        (new ContractDocumentValidator)->validate(['document' => $document, 'variables' => $versions], $types);
        if (array_diff(array_keys($values), array_keys($types)) !== []) {
            $this->invalid();
        }
        $validator = new ContractVariableValueValidator;
        foreach ($types as $id => $definition) {
            $values[$id] = $validator->validate($definition, $values[$id] ?? null, static function (string $type, int $entityId) use ($entitySnapshots): bool {
                $label = $entitySnapshots[$type.':'.$entityId]['label'] ?? null;

                return is_string($label) && trim($label) !== '' && mb_strlen($label) <= 1000;
            });
        }
        $expanded = 0;
        $expand = function (array $node, ?array $row = null) use (&$expand, &$expanded, $types, $values, $entitySnapshots): array {
            if (++$expanded > 100000) {
                $this->invalid();
            }
            $attrs = $node['attrs'] ?? [];
            if ($node['type'] === 'blockReference') {
                $this->invalid();
            }
            if ($node['type'] === 'conditional') {
                if (($values[$attrs['variableId']] ?? null) !== true) {
                    return [];
                }
                $children = [];
                foreach ($node['content'] ?? [] as $child) {
                    array_push($children, ...$expand($child, $row));
                }

                return isset($attrs['layout']) ? [['type' => 'group', 'attrs' => ['layout' => $attrs['layout']], 'content' => $children]] : $children;
            }
            if ($node['type'] === 'repeatRows') {
                $children = [];
                foreach ($values[$attrs['variableId']] ?? [] as $tableRow) {
                    foreach ($node['content'] as $child) {
                        array_push($children, ...$expand($child, $tableRow));
                    }
                }

                return $children;
            }
            if ($node['type'] === 'variable') {
                $definition = $types[$attrs['variableId']];
                $value = $values[$attrs['variableId']];
                if (isset($attrs['columnId'])) {
                    $definition = array_column($definition['columns'], 'definition', 'id')[$attrs['columnId']];
                    $value = $row['values'][$attrs['columnId']] ?? null;
                }

                return [['type' => 'text', 'text' => $this->format($definition, $value, $entitySnapshots)]];
            }
            if (isset($node['content'])) {
                $children = [];
                foreach ($node['content'] as $child) {
                    array_push($children, ...$expand($child, $row));
                }
                $node['content'] = $children;
            }

            return [$node];
        };
        $resolved = $expand($document)[0];
        $numbers = [];
        $counters = [];
        $number = function (array $node, string $parent = '') use (&$number, &$numbers, &$counters): void {
            if ($node['type'] === 'clause') {
                $id = $node['attrs']['id'];
                if (isset($numbers[$id])) {
                    $this->invalid();
                }
                $counters[$parent] = ($counters[$parent] ?? 0) + 1;
                $parent = ($parent === '' ? '' : $parent.'.').$counters[$parent];
                $numbers[$id] = $parent;
            }
            foreach ($node['content'] ?? [] as $child) {
                $number($child, $parent);
            }
        };
        $number($resolved);
        $layout = $resolved['attrs']['layout'] ?? null;
        if ($layout !== null) {
            $bottom = 0;
            $blocks = [];
            [$pageWidth] = (new ContractDocumentPrintLayout)->size($layout['page']);
            $width = $pageWidth / ContractDocumentPrintMeasure::PT_PER_MM - $layout['page']['margins']['left'] - $layout['page']['margins']['right'];
            foreach ($resolved['content'] as $index => $node) {
                $geometry = $node['attrs']['layout'] ?? ['id' => 'legacy-'.$index, 'x' => 0, 'y' => $bottom, 'width' => $width, 'minHeight' => 0];
                $bottom = $geometry['y'] + max(14, $geometry['minHeight']) + 4;
                $blocks[] = $this->printBlock($node, $geometry, $numbers);
            }
            $html = (new ContractDocumentPrintLayout)->render($layout, $blocks);
        } else {
            $html = $this->html($resolved, $numbers);
        }
        if (strlen($html) > 10485760) {
            $this->invalid();
        }

        return $html;
    }

    private function printBlock(array $node, array $geometry, array $numbers): array
    {
        $block = ['layout' => $geometry, 'html' => $this->html($node, $numbers)];
        if ($node['type'] !== 'group') {
            return $block;
        }
        $block['children'] = [];
        $bottom = 0;
        foreach ($node['content'] as $index => $child) {
            $childGeometry = $child['attrs']['layout'] ?? ['id' => 'child-'.$index, 'x' => 0, 'y' => $bottom, 'width' => $geometry['width'], 'minHeight' => 0];
            $bottom = $childGeometry['y'] + max(14, $childGeometry['minHeight']) + 4;
            $block['children'][] = $this->printBlock($child, $childGeometry, $numbers);
        }

        return $block;
    }

    private function html(array $node, array $numbers): string
    {
        $attrs = $node['attrs'] ?? [];
        if ($node['type'] === 'text') {
            $text = $this->escape($node['text']);
            foreach ($node['marks'] ?? [] as $mark) {
                $tag = match ($mark['type']) {
                    'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'link' => 'a',
                };
                $link = $mark['type'] === 'link' ? ' href="'.$this->escape($mark['attrs']['href']).'" rel="noopener noreferrer"' : '';
                $text = '<'.$tag.$link.'>'.$text.'</'.$tag.'>';
            }

            return $text;
        }
        if ($node['type'] === 'hardBreak') {
            return '<br>';
        }
        if ($node['type'] === 'clauseReference') {
            if (! isset($numbers[$attrs['target']])) {
                $this->invalid();
            }

            return '<a href="#clause-'.$this->escape($attrs['target']).'">'.$numbers[$attrs['target']].'</a>';
        }
        $children = implode('', array_map(fn (array $child): string => $this->html($child, $numbers), $node['content'] ?? []));
        $tag = match ($node['type']) {
            'doc' => 'article', 'paragraph' => 'p', 'heading' => 'h'.$attrs['level'],
            'clause', 'group' => 'section', 'orderedList' => 'ol', 'bulletList' => 'ul', 'listItem' => 'li',
            'table' => 'table', 'tableRow' => 'tr', 'tableCell' => 'td',
            default => $this->invalid(),
        };
        $attributes = '';
        if ($node['type'] === 'doc') {
            $attributes = ' class="contract-document"';
        } elseif ($node['type'] === 'clause') {
            $attributes = ' id="clause-'.$this->escape($attrs['id']).'"';
            $children = '<p class="contract-clause-number">'.$numbers[$attrs['id']].'.</p>'.$children;
        } elseif ($node['type'] === 'orderedList') {
            $attributes = ' start="'.($attrs['start'] ?? 1).'"';
        } elseif ($node['type'] === 'tableCell') {
            $attributes = ' colspan="'.($attrs['colspan'] ?? 1).'" rowspan="'.($attrs['rowspan'] ?? 1).'"';
        }

        return '<'.$tag.$attributes.'>'.$children.'</'.$tag.'>';
    }

    private function format(array $definition, mixed $value, array $entitySnapshots): string
    {
        if ($value === null) {
            return '';
        }

        return match ($definition['type']) {
            'text' => (string) $value,
            'number' => $this->decimal((string) $value),
            'money' => $this->decimal($value['amount'], true)."\u{00A0}".($value['currency'] === 'RUB' ? '₽' : $value['currency']),
            'percentage' => $this->decimal((string) $value)."\u{00A0}%",
            'boolean' => trans_message($value ? 'contracts.variable_true' : 'contracts.variable_false'),
            'date' => implode('.', array_reverse(explode('-', $value))),
            'choice' => array_column($definition['options'], 'label', 'id')[$value],
            'entity' => $entitySnapshots[$value['type'].':'.$value['id']]['label'],
            default => $this->invalid(),
        };
    }

    private function decimal(string $value, bool $money = false): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $parts)) {
            return $value;
        }

        $fraction = rtrim($parts[3] ?? '', '0');
        if ($money) {
            $fraction = str_pad($fraction, 2, '0');
        }
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", $parts[2]);

        return $parts[1].$integer.($fraction !== '' ? ','.$fraction : '');
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_document_invalid', 422);
    }
}
