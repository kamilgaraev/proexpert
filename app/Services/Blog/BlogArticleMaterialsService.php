<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogMediaAsset;
use Illuminate\Validation\ValidationException;

class BlogArticleMaterialsService
{
    public function normalize(array $document): array
    {
        $urls = [];

        foreach ($document as $index => $block) {
            if (($block['type'] ?? null) !== 'materials') {
                continue;
            }

            $items = $block['data']['items'] ?? [];

            if (! is_array($items) || count($items) > 10) {
                $this->reject($index);
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['url'] ?? '')) {
                    $this->reject($index);
                }

                if (! empty($item['url'])) {
                    $urls[] = $item['url'];
                }
            }
        }

        if ($urls === []) {
            return $document;
        }

        $assets = BlogMediaAsset::query()
            ->where('blog_context', BlogContextEnum::MARKETING->value)
            ->whereIn('mime_type', BlogMediaService::allowedDocumentMimeTypes())
            ->whereIn('public_url', array_unique($urls))
            ->get()
            ->keyBy('public_url');

        foreach ($document as $index => &$block) {
            if (($block['type'] ?? null) !== 'materials') {
                continue;
            }

            $items = [];

            foreach ($block['data']['items'] ?? [] as $item) {
                $url = $item['url'] ?? '';

                if ($url === '') {
                    continue;
                }

                $asset = $assets->get($url);

                if ($asset === null || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                    $this->reject($index);
                }

                if (! is_string($item['label'] ?? '') || ! is_string($item['description'] ?? '')) {
                    $this->reject($index);
                }

                $label = trim((string) ($item['label'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));

                if (mb_strlen($label) > 160 || mb_strlen($description) > 500) {
                    $this->reject($index);
                }

                $items[$url] = [
                    'url' => $asset->public_url,
                    'label' => $label !== '' ? $label : $asset->filename,
                    'description' => $description,
                    'filename' => $asset->filename,
                    'file_size' => $asset->file_size,
                    'format' => match ($asset->mime_type) {
                        'application/pdf' => 'PDF',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                        default => 'XLSX',
                    },
                ];
            }

            $block['data'] = ['items' => array_values($items)];
        }
        unset($block);

        return $document;
    }

    private function reject(int $index): never
    {
        throw ValidationException::withMessages([
            'editor_document.' . $index => [trans_message('blog_cms.materials_invalid')],
        ]);
    }
}
