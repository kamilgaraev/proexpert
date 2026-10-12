<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractDocumentRenderer
{
    private string $locale = 'ru-RU';

    public function render(array $document, array $definitions, array $values, array $entitySnapshots = []): string
    {
        $this->locale = ContractDocumentLocale::validate($document['attrs']['locale'] ?? 'ru-RU');
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
        $decorations = $resolved['attrs']['pageDecorations'] ?? null;
        $imageCount = $imageBytes = 0;
        $countImages = function (array $node) use (&$countImages, &$imageCount, &$imageBytes): void {
            if ($node['type'] === 'image') {
                $imageBytes += strlen(ContractInlineImage::bytes($node['attrs']['src']));
                if (++$imageCount > 20 || $imageBytes > 2 * 1024 * 1024) { throw new ContractBuilderException('contracts.builder_image_invalid', 422); }
            }
            foreach ($node['content'] ?? [] as $child) { $countImages($child); }
        };
        $countImages($resolved);
        if ($layout === null && ($decorations !== null || $imageCount > 0)) { $layout = ContractPageDecorations::defaultLayout(); }
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
            $html = (new ContractDocumentPrintLayout)->render($layout, $blocks, $decorations, $this->locale);
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
        $fit = function (array $item, float $available) use (&$fit): array {
            if ($item['type'] === 'image' && $item['attrs']['width'] > $available) {
                $item['attrs']['height'] *= $available / $item['attrs']['width'];
                $item['attrs']['width'] = $available;
            }
            if ($item['type'] === 'table') {
                $rows = $item['content'];
                $columns = count($item['attrs']['columnWidths'] ?? []) ?: max(array_map(static fn (array $row): int => array_sum(array_map(static fn (array $cell): int => $cell['attrs']['colspan'] ?? 1, $row['content'])), $rows));
                $widths = $item['attrs']['columnWidths'] ?? array_fill(0, $columns, 100 / $columns);
                $occupied = [];
                foreach ($rows as $rowIndex => $row) {
                    $column = 0;
                    foreach ($row['content'] as $cellIndex => $cell) {
                        while (($occupied[$column] ?? -1) >= $rowIndex) { $column++; }
                        $span = $cell['attrs']['colspan'] ?? 1;
                        $width = $available * array_sum(array_slice($widths, $column, $span)) / 100;
                        $rows[$rowIndex]['content'][$cellIndex] = $fit($cell, max(.5, $width - 11 / ContractDocumentPrintMeasure::PT_PER_MM));
                        for ($offset = 0; $offset < $span; $offset++) { $occupied[$column + $offset] = $rowIndex + ($cell['attrs']['rowspan'] ?? 1) - 1; }
                        $column += $span;
                    }
                }
                $item['content'] = $rows;
            } elseif (isset($item['content'])) { $item['content'] = array_map(static fn (array $child): array => $fit($child, $available), $item['content']); }
            return $item;
        };
        $node = $fit($node, (float) $geometry['width']);
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

    private function html(array $node, array $numbers, array $inheritedTypography = []): string
    {
        $attrs = $node['attrs'] ?? [];
        $layout = $attrs['layout'] ?? [];
        $typography = array_replace($inheritedTypography, array_intersect_key($layout, ['fontFamily' => true, 'fontSize' => true]));
        $typography = array_replace($typography, $attrs['format'] ?? []);
        if ($node['type'] === 'text') {
            $text = $this->escape($node['text']);
            foreach ($node['marks'] ?? [] as $mark) {
                $tag = match ($mark['type']) {
                    'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'link' => 'a',
                    'textStyle' => 'span', 'superscript' => 'sup', 'subscript' => 'sub',
                };
                $link = $mark['type'] === 'link' ? ' href="'.$this->escape($mark['attrs']['href']).'" rel="noopener noreferrer"' : '';
                if ($mark['type'] === 'textStyle') {
                    $markStyle = $mark['attrs'];
                    $link = ' style="'.$this->escape(ContractTypography::style($markStyle)).'"'
                        .(isset($markStyle['fontFamily']) ? ' data-contract-font="'.$this->escape($markStyle['fontFamily']).'"' : '')
                        .(isset($markStyle['color']) ? ' data-contract-color="'.$this->escape($markStyle['color']).'"' : '');
                }
                $text = '<'.$tag.$link.'>'.$text.'</'.$tag.'>';
            }

            return $text;
        }
        if ($node['type'] === 'hardBreak') {
            return '<br>';
        }
        if ($node['type'] === 'image') {
            return '<img src="'.$this->escape($attrs['src']).'" alt="'.$this->escape($attrs['alt']).'" style="width:'.$attrs['width'].'mm;height:'.$attrs['height'].'mm">';
        }
        if ($node['type'] === 'clauseReference') {
            if (! isset($numbers[$attrs['target']])) {
                $this->invalid();
            }

            return '<a href="#clause-'.$this->escape($attrs['target']).'">'.$numbers[$attrs['target']].'</a>';
        }
        $children = implode('', array_map(fn (array $child): string => $this->html($child, $numbers, $typography), $node['content'] ?? []));
        $tag = match ($node['type']) {
            'doc' => 'article', 'paragraph' => 'p', 'heading' => 'h'.$attrs['level'],
            'clause', 'group' => 'section', 'orderedList' => 'ol', 'bulletList' => 'ul', 'listItem' => 'li',
            'table' => 'table', 'tableRow' => 'tr', 'tableCell' => 'td',
            default => $this->invalid(),
        };
        $attributes = '';
        if ($node['type'] === 'doc') {
            $attributes = ' class="contract-document" lang="'.$this->locale.'" dir="ltr"';
        } elseif ($node['type'] === 'clause') {
            $attributes = ' id="clause-'.$this->escape($attrs['id']).'"';
            $numberStyle = $this->typographyStyle($typography);
            $number = '<p class="contract-clause-number"'.($numberStyle !== '' ? ' style="'.$this->escape($numberStyle).'"' : '').'>'.$numbers[$attrs['id']].'.</p>';
            if (($attrs['textPlacement'] ?? 'inline') === 'inline' && ($node['content'][0]['type'] ?? null) === 'paragraph') {
                $first = $node['content'][0];
                $inline = implode('', array_map(fn (array $child): string => $this->html($child, $numbers, $typography), $first['content'] ?? []));
                $rest = array_slice($node['content'], 1);
                $paragraphTypography = array_replace($typography, array_intersect_key($first['attrs']['layout'] ?? [], ['fontFamily' => true, 'fontSize' => true]), $first['attrs']['format'] ?? []);
                $paragraphStyle = $this->typographyStyle($paragraphTypography);
                $children = '<p'.($paragraphStyle !== '' ? ' style="'.$this->escape($paragraphStyle).'"' : '')
                    .(isset($paragraphTypography['fontFamily']) ? ' data-contract-font="'.$this->escape($paragraphTypography['fontFamily']).'"' : '')
                    .(isset($paragraphTypography['color']) ? ' data-contract-color="'.$this->escape($paragraphTypography['color']).'"' : '')
                    .'><strong class="contract-clause-number">'.$numbers[$attrs['id']].'. </strong>'.$inline.'</p>'
                    .implode('', array_map(fn (array $child): string => $this->html($child, $numbers, $typography), $rest));
            } else {
                $children = $number.$children;
            }
        } elseif ($node['type'] === 'orderedList') {
            $attributes = ' start="'.($attrs['start'] ?? 1).'"';
        } elseif ($node['type'] === 'tableCell') {
            $attributes = ' colspan="'.($attrs['colspan'] ?? 1).'" rowspan="'.($attrs['rowspan'] ?? 1).'"';
        } elseif ($node['type'] === 'tableRow' && ($attrs['header'] ?? false)) {
            $attributes = ' data-contract-repeat-header="1"';
        } elseif ($node['type'] === 'table') {
            $header = $body = '';
            foreach ($node['content'] as $row) {
                if ($row['attrs']['header'] ?? false) { $header .= $this->html($row, $numbers, $typography); }
                else { $body .= $this->html($row, $numbers, $typography); }
            }
            $children = ($header === '' ? '' : '<thead>'.$header.'</thead>').'<tbody>'.$body.'</tbody>';
            if (isset($attrs['columnWidths'])) {
                $children = '<colgroup>'.implode('', array_map(static fn ($width): string => '<col style="width:'.$width.'%">', $attrs['columnWidths'])).'</colgroup>'.$children;
            }
        }

        if (in_array($node['type'], ['paragraph', 'heading', 'clause', 'group', 'conditional', 'table', 'orderedList', 'bulletList'], true)
            && ($typography !== [] || $layout !== [])) {
            $styleTypography = $typography;
            if ($node['type'] === 'heading' && ! isset($layout['fontSize'])) {
                unset($styleTypography['fontSize']);
            }
            $style = $this->typographyStyle($styleTypography);
            if ($style !== '') {
                $attributes .= ' style="'.$this->escape($style).'"'
                    .(isset($styleTypography['fontFamily']) ? ' data-contract-font="'.$this->escape($styleTypography['fontFamily']).'"' : '')
                    .(isset($styleTypography['color']) ? ' data-contract-color="'.$this->escape($styleTypography['color']).'"' : '');
            }
        }

        return '<'.$tag.$attributes.'>'.$children.'</'.$tag.'>';
    }

    private function typographyStyle(array $typography): string
    {
        if ($typography === []) {
            return '';
        }
        return ContractTypography::style($typography);
    }

    public function formatValue(array $definition, mixed $value, array $entitySnapshots = [], string $locale = 'ru-RU'): string
    {
        return $this->format($definition, $value, $entitySnapshots, ContractDocumentLocale::validate($locale));
    }

    private function format(array $definition, mixed $value, array $entitySnapshots, ?string $locale = null): string
    {
        if ($value === null) {
            return '';
        }

        $locale ??= $this->locale;
        return match ($definition['type']) {
            'text' => (string) $value,
            'number' => ContractDocumentLocale::decimal((string) $value, false, $locale),
            'money' => ContractDocumentLocale::decimal($value['amount'], true, $locale)."\u{00A0}".($value['currency'] === 'RUB' && $locale === 'ru-RU' ? '₽' : $value['currency']),
            'percentage' => ContractDocumentLocale::decimal((string) $value, false, $locale)."\u{00A0}%",
            'boolean' => ContractDocumentLocale::boolean($value, $locale),
            'date' => ContractDocumentLocale::date($value, $locale),
            'choice' => array_column($definition['options'], 'label', 'id')[$value],
            'entity' => $entitySnapshots[$value['type'].':'.$value['id']]['label'],
            'table' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => $this->invalid(),
        };
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
