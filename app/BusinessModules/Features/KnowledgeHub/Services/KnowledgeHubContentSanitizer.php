<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class KnowledgeHubContentSanitizer
{
    /** @var array<string, list<string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'h2' => ['id'],
        'h3' => ['id'],
    ];

    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'a',
        'b',
        'blockquote',
        'br',
        'code',
        'em',
        'h2',
        'h3',
        'i',
        'li',
        'mark',
        'ol',
        'p',
        'pre',
        'span',
        'strong',
        'u',
        'ul',
    ];

    /** @var list<string> */
    private const DROP_WITH_CONTENT = [
        'button',
        'embed',
        'form',
        'iframe',
        'input',
        'link',
        'math',
        'meta',
        'object',
        'script',
        'select',
        'style',
        'svg',
        'textarea',
    ];

    public static function clean(string $content): string
    {
        if (trim($content) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$content.'</div>',
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $root = $document->documentElement;
        if (! $root instanceof DOMElement) {
            return '';
        }

        self::sanitizeChildren($root, $document);

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    private static function sanitizeChildren(DOMNode $node, DOMDocument $document): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            self::sanitizeElement($child, $document);
        }
    }

    private static function sanitizeElement(DOMElement $element, DOMDocument $document): void
    {
        $tagName = strtolower($element->tagName);

        if (in_array($tagName, self::DROP_WITH_CONTENT, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        self::sanitizeChildren($element, $document);

        if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
            self::unwrapElement($element);

            return;
        }

        self::sanitizeAttributes($element, $tagName);
    }

    private static function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function sanitizeAttributes(DOMElement $element, string $tagName): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $allowed = self::ALLOWED_ATTRIBUTES[$tagName] ?? [];

            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($tagName === 'a' && $name === 'href' && ! self::isSafeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }

            if (in_array($tagName, ['h2', 'h3'], true) && $name === 'id') {
                $safeId = preg_replace('/[^a-zA-Z0-9\-_:.]/', '', $attribute->value) ?? '';

                if ($safeId === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('id', $safeId);
                }
            }
        }

        if ($tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/u', '', html_entity_decode(trim($url))));

        return str_starts_with($normalized, '#')
            || str_starts_with($normalized, '/')
            || str_starts_with($normalized, 'http://')
            || str_starts_with($normalized, 'https://')
            || str_starts_with($normalized, 'mailto:');
    }
}
