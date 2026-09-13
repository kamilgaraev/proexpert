<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Models\Blog\BlogArticle;
use Illuminate\Validation\ValidationException;

final class BlogLegacyDocumentService
{
    public function forEditor(BlogArticle $article): array
    {
        return $this->normalize($article->editor_document ?? [], $article);
    }

    public function normalize(array $document, ?BlogArticle $article): array
    {
        $html = $this->originalHtml($article);
        $blocks = [];

        foreach ($document as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'legacy_html') {
                if ($html === '') {
                    throw ValidationException::withMessages([
                        'editor_document' => [trans_message('blog_cms.legacy_content_invalid')],
                    ]);
                }

                continue;
            }

            $blocks[] = $block;
        }

        if ($html !== '') {
            array_unshift($blocks, ['type' => 'legacy_html', 'data' => ['html' => $html]]);
        }

        return $blocks;
    }

    private function originalHtml(?BlogArticle $article): string
    {
        if ($article === null) {
            return '';
        }

        $document = $article->editor_document ?? [];

        if ($document === []) {
            return (string) $article->content;
        }

        foreach ($document as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'legacy_html') {
                return (string) ($block['data']['html'] ?? '');
            }
        }

        return '';
    }
}
