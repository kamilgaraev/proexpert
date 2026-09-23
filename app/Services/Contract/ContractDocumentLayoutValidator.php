<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractDocumentLayoutValidator
{
    public function validate(array $document): void
    {
        $layout = $document['attrs']['layout'] ?? null;
        if (array_key_exists('layout', $document['attrs'] ?? [])) {
            $this->page($layout);
        }
        $seen = [];
        $visit = function (array $node) use (&$visit, &$seen): void {
            if (($node['type'] ?? null) !== 'doc' && array_key_exists('layout', $node['attrs'] ?? [])) {
                $block = $node['attrs']['layout'];
                $this->block($block);
                if (isset($seen[$block['id']])) {
                    $this->invalid();
                }
                $seen[$block['id']] = true;
            }
            foreach ($node['content'] ?? [] as $child) {
                $visit($child);
            }
        };
        $visit($document);
    }

    public function page(mixed $layout): void
    {
        $this->keys($layout, ['version', 'page', 'grid']);
        if (($layout['version'] ?? null) !== 1) {
            $this->invalid();
        }
        $page = $layout['page'] ?? null;
        $this->keys($page, ['format', 'orientation', 'margins']);
        if (! in_array($page['format'] ?? null, ['A4', 'A5', 'Letter'], true)
            || ! in_array($page['orientation'] ?? null, ['portrait', 'landscape'], true)) {
            $this->invalid();
        }
        $margins = $page['margins'] ?? null;
        $this->keys($margins, ['top', 'right', 'bottom', 'left']);
        foreach (['top', 'right', 'bottom', 'left'] as $edge) {
            $this->number($margins[$edge] ?? null, 0, 50);
        }
        $grid = $layout['grid'] ?? null;
        $this->keys($grid, ['size', 'snap', 'visible']);
        $this->number($grid['size'] ?? null, 1, 20);
        if (! is_bool($grid['snap'] ?? null) || ! is_bool($grid['visible'] ?? null)) {
            $this->invalid();
        }
    }

    public function block(mixed $block): void
    {
        $this->keys($block, ['id', 'x', 'y', 'width', 'minHeight', 'breakBefore', 'align', 'firstLineIndent', 'lineHeight', 'spaceAfter', 'fontFamily', 'fontSize']);
        if (! is_string($block['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $block['id']) !== 1) {
            $this->invalid();
        }
        foreach (['x' => [0, 297], 'y' => [0, 100000], 'width' => [10, 297], 'minHeight' => [0, 5000],
            'firstLineIndent' => [0, 50], 'lineHeight' => [1, 3], 'spaceAfter' => [0, 50], 'fontSize' => [8, 20]] as $field => [$min, $max]) {
            if (in_array($field, ['x', 'y', 'width', 'minHeight'], true) || array_key_exists($field, $block)) {
                $this->number($block[$field] ?? null, $min, $max);
            }
        }
        if ((array_key_exists('fontFamily', $block) && ! in_array($block['fontFamily'], ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono', 'Most Contract'], true))
            || (array_key_exists('breakBefore', $block) && ! is_bool($block['breakBefore']))
            || (array_key_exists('align', $block) && ! in_array($block['align'], ['left', 'center', 'right', 'justify'], true))) {
            $this->invalid();
        }
    }

    private function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_diff(array_keys($value), $keys) !== []) {
            $this->invalid();
        }
    }

    private function number(mixed $value, float $min, float $max): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_document_invalid', 422);
    }
}
