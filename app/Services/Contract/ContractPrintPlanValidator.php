<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractPrintPlanValidator
{
    public function validate(mixed $plan): void
    {
        if (! is_array($plan) || array_diff(array_keys($plan), ['version', 'page', 'width', 'height', 'pages']) !== []
            || ($plan['version'] ?? null) !== 1 || ! is_array($plan['page'] ?? null)
            || ! is_array($plan['pages'] ?? null) || ! array_is_list($plan['pages']) || count($plan['pages']) < 1 || count($plan['pages']) > 501) {
            $this->invalid();
        }
        (new ContractDocumentLayoutValidator)->page(['version' => 1, 'page' => $plan['page'], 'grid' => ['size' => 5, 'snap' => true, 'visible' => true]]);
        [$width, $height] = (new ContractDocumentPrintLayout)->size($plan['page']);
        if (! $this->number($plan['width'] ?? null, $width - .01, $width + .01) || ! $this->number($plan['height'] ?? null, $height - .01, $height + .01)) {
            $this->invalid();
        }
        $count = 0;
        foreach ($plan['pages'] as $items) {
            if (! is_array($items) || ! array_is_list($items)) {
                $this->invalid();
            }
            foreach ($items as $item) {
                if (++$count > 100000 || ! is_array($item) || ! in_array($item['type'] ?? null, ['text', 'rect', 'anchor'], true)) {
                    $this->invalid();
                }
                $keys = ['type', 'x', 'y', 'width', 'height'];
                if ($item['type'] === 'text') {
                    array_push($keys, 'text', 'fontFamily', 'fontSize', 'bold', 'italic', 'underline', 'strike', 'wordSpacing', 'letterSpacing', 'href');
                    if (! is_string($item['text'] ?? null) || strlen($item['text']) > 100000
                        || ! in_array($item['fontFamily'] ?? null, ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono', 'Most Contract'], true)
                        || ! $this->number($item['fontSize'] ?? null, 1, 72)
                        || ! $this->number($item['wordSpacing'] ?? null, -20, 500)
                        || ! $this->number($item['letterSpacing'] ?? null, -20, 100)) {
                        $this->invalid();
                    }
                    foreach (['bold', 'italic', 'underline', 'strike'] as $flag) {
                        if (! is_bool($item[$flag] ?? null)) {
                            $this->invalid();
                        }
                    }
                    $href = $item['href'] ?? null;
                    if ($href !== null && (! is_string($href) || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7f]/', $href) === 1
                        || preg_match('~^(?:https?://|mailto:|#[A-Za-z0-9_-]+$)~i', $href) !== 1)) {
                        $this->invalid();
                    }
                } elseif ($item['type'] === 'anchor') {
                    $keys[] = 'name';
                    if (! is_string($item['name'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,550}$/D', $item['name']) !== 1) {
                        $this->invalid();
                    }
                }
                if (array_diff(array_keys($item), $keys) !== [] || ! $this->number($item['x'] ?? null, -1, $width + 1)
                    || ! $this->number($item['y'] ?? null, -1, $height + 1)
                    || ! $this->number($item['width'] ?? null, 0, $width + 1)
                    || ! $this->number($item['height'] ?? null, 0, $height + 1)) {
                    $this->invalid();
                }
            }
        }
    }

    private function number(mixed $value, float $min, float $max): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $min && $value <= $max;
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_export_invalid', 422);
    }
}
