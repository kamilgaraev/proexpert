<?php

declare(strict_types=1);

namespace App\Services\Blog;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BlogDocumentRenderer
{
    public function render(?array $document): string
    {
        if (empty($document)) {
            return '';
        }

        return collect($document)
            ->map(fn (mixed $block): string => is_array($block) ? $this->renderBlock($block) : '')
            ->implode("\n");
    }

    public function estimateReadingTime(string $html): int
    {
        $wordCount = str_word_count(strip_tags($html));

        return max(1, (int) ceil($wordCount / 200));
    }

    private function renderBlock(array $block): string
    {
        $type = (string) Arr::get($block, 'type');
        $data = Arr::get($block, 'data', []);

        if (!is_array($data)) {
            $data = [];
        }

        return match ($type) {
            'heading' => $this->renderHeading($data),
            'list' => $this->renderList($data),
            'quote' => $this->renderQuote($data),
            'image' => $this->renderImage($data),
            'gallery' => $this->renderGallery($data),
            'table' => $this->renderTable($data),
            'code' => $this->renderCode($data),
            'divider' => '<hr class="blog-divider" />',
            'callout' => $this->renderCallout($data),
            'embed' => $this->renderEmbed($data),
            'cta' => $this->renderCta($data),
            default => $this->renderParagraph($data),
        };
    }

    private function renderParagraph(array $data): string
    {
        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            return '';
        }

        return '<p>' . nl2br(e($content)) . '</p>';
    }

    private function renderHeading(array $data): string
    {
        $content = trim((string) ($data['content'] ?? ''));
        $level = max(2, min(4, (int) ($data['level'] ?? 2)));

        if ($content === '') {
            return '';
        }

        return sprintf('<h%d id="%s">%s</h%d>', $level, Str::slug($content), e($content), $level);
    }

    private function renderList(array $data): string
    {
        $items = Arr::wrap($data['items'] ?? []);
        $tag = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $htmlItems = collect($items)
            ->map(function (mixed $item): string {
                $value = is_array($item) ? (string) ($item['value'] ?? '') : (string) $item;

                return $value === '' ? '' : '<li>' . nl2br(e($value)) . '</li>';
            })
            ->filter()
            ->implode('');

        return $htmlItems === '' ? '' : sprintf('<%1$s>%2$s</%1$s>', $tag, $htmlItems);
    }

    private function renderQuote(array $data): string
    {
        $content = trim((string) ($data['content'] ?? ''));
        $caption = trim((string) ($data['caption'] ?? ''));

        if ($content === '') {
            return '';
        }

        $captionHtml = $caption !== '' ? '<cite>' . e($caption) . '</cite>' : '';

        return '<blockquote><p>' . nl2br(e($content)) . '</p>' . $captionHtml . '</blockquote>';
    }

    private function renderImage(array $data): string
    {
        $src = $this->sanitizeUrl((string) ($data['url'] ?? ''));

        if ($src === null) {
            return '';
        }

        $alt = (string) ($data['alt'] ?? '');
        $caption = trim((string) ($data['caption'] ?? ''));
        $captionHtml = $caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '';

        return '<figure><img src="' . e($src) . '" alt="' . e($alt) . '">' . $captionHtml . '</figure>';
    }

    private function renderGallery(array $data): string
    {
        $images = Arr::wrap($data['images'] ?? []);
        $items = collect($images)
            ->map(function (mixed $image): string {
                if (!is_array($image)) {
                    return '';
                }

                $src = $this->sanitizeUrl((string) ($image['url'] ?? ''));

                if ($src === null) {
                    return '';
                }

                return '<figure><img src="' . e($src) . '" alt="' . e((string) ($image['alt'] ?? '')) . '"></figure>';
            })
            ->filter()
            ->implode('');

        return $items === '' ? '' : '<div class="blog-gallery">' . $items . '</div>';
    }

    private function renderTable(array $data): string
    {
        $headers = Arr::wrap($data['headers'] ?? []);
        $rows = Arr::wrap($data['rows'] ?? []);
        $headHtml = collect($headers)
            ->map(function (mixed $cell): string {
                $value = is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;

                return $value === '' ? '' : '<th>' . e($value) . '</th>';
            })
            ->filter()
            ->implode('');
        $bodyHtml = collect($rows)->map(function (mixed $row): string {
            if (!is_array($row)) {
                return '';
            }

            $cells = collect(Arr::wrap($row['cells'] ?? $row))
                ->map(function (mixed $cell): string {
                    $value = is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;

                    return $value === '' ? '' : '<td>' . nl2br(e($value)) . '</td>';
                })
                ->filter()
                ->implode('');

            return $cells === '' ? '' : '<tr>' . $cells . '</tr>';
        })->implode('');

        if ($headHtml === '' && $bodyHtml === '') {
            return '';
        }

        $thead = $headHtml !== '' ? '<thead><tr>' . $headHtml . '</tr></thead>' : '';
        $tbody = $bodyHtml !== '' ? '<tbody>' . $bodyHtml . '</tbody>' : '';

        return '<div class="blog-table"><table>' . $thead . $tbody . '</table></div>';
    }

    private function renderCode(array $data): string
    {
        $content = (string) ($data['content'] ?? '');

        if ($content === '') {
            return '';
        }

        $language = trim((string) ($data['language'] ?? ''));
        $class = $language !== '' ? ' class="language-' . e($language) . '"' : '';

        return '<pre><code' . $class . '>' . e($content) . '</code></pre>';
    }

    private function renderCallout(array $data): string
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $variant = Str::slug((string) ($data['variant'] ?? 'info'));

        if ($title === '' && $content === '') {
            return '';
        }

        $titleHtml = $title !== '' ? '<strong>' . e($title) . '</strong>' : '';
        $contentHtml = $content !== '' ? '<p>' . nl2br(e($content)) . '</p>' : '';

        return '<aside class="blog-callout blog-callout-' . e($variant) . '">' . $titleHtml . $contentHtml . '</aside>';
    }

    private function renderEmbed(array $data): string
    {
        $url = $this->sanitizeUrl((string) ($data['url'] ?? ''));

        if ($url === null) {
            return '';
        }

        $embedUrl = $this->resolveEmbedUrl($url);

        if ($embedUrl === null) {
            return '<p><a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($url) . '</a></p>';
        }

        return '<div class="blog-embed"><iframe src="' . e($embedUrl) . '" loading="lazy" allowfullscreen></iframe></div>';
    }

    private function renderCta(array $data): string
    {
        $label = trim((string) ($data['label'] ?? ''));
        $url = $this->sanitizeUrl((string) ($data['url'] ?? ''));

        if ($label === '' || $url === null) {
            return '';
        }

        $description = trim((string) ($data['description'] ?? ''));
        $descriptionHtml = $description !== '' ? '<p>' . e($description) . '</p>' : '';

        return '<div class="blog-cta">' . $descriptionHtml . '<a href="' . e($url) . '" class="blog-cta-link">' . e($label) . '</a></div>';
    }

    private function sanitizeUrl(string $url): ?string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return null;
        }

        if (Str::startsWith($trimmed, ['/', '#'])) {
            return $trimmed;
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : null;
    }

    private function resolveEmbedUrl(string $url): ?string
    {
        if (preg_match('/youtube\.com\/watch\?v=([^&]+)/', $url, $matches) === 1) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        if (preg_match('/youtu\.be\/([^?]+)/', $url, $matches) === 1) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches) === 1) {
            return 'https://player.vimeo.com/video/' . $matches[1];
        }

        return null;
    }
}
