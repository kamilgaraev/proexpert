<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogMediaAsset;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BlogEditorialChecklistService
{
    private const TITLE_MIN_LENGTH = 10;
    private const TITLE_MAX_LENGTH = 90;
    private const EXCERPT_MAX_LENGTH = 500;
    private const SEO_DESCRIPTION_MAX_LENGTH = 160;
    private const BODY_MIN_WORDS = 60;

    public function __construct(
        private readonly BlogDocumentRenderer $documentRenderer,
    ) {
    }

    /**
     * @return array{
     *     items: array<int, array{key: string, label: string, passed: bool, required: bool, message: string}>,
     *     total: int,
     *     passed: int,
     *     required_total: int,
     *     required_passed: int,
     *     can_publish: bool,
     *     blocking_keys: array<int, string>
     * }
     */
    public function evaluate(BlogArticle|array $source, ?int $ignoreArticleId = null): array
    {
        $state = $this->state($source);
        $ignoreArticleId ??= $source instanceof BlogArticle ? $source->id : null;

        $title = trim((string) Arr::get($state, 'title', ''));
        $slug = trim((string) Arr::get($state, 'slug', ''));
        $excerpt = trim((string) Arr::get($state, 'excerpt', ''));
        $featuredImage = trim((string) Arr::get($state, 'featured_image', ''));
        $metaTitle = trim((string) Arr::get($state, 'meta_title', ''));
        $metaDescription = trim((string) Arr::get($state, 'meta_description', ''));
        $canonicalUrl = trim((string) Arr::get($state, 'canonical_url', ''));
        $document = Arr::wrap(Arr::get($state, 'editor_document', []));
        $content = $document !== []
            ? $this->documentRenderer->render($document)
            : (string) Arr::get($state, 'content', '');
        $status = Arr::get($state, 'status', BlogArticleStatusEnum::DRAFT->value);
        $status = $status instanceof BlogArticleStatusEnum ? $status->value : (string) $status;
        $scheduledAt = $this->parseDateTime(Arr::get($state, 'scheduled_at'));

        $items = [
            $this->item(
                'title',
                trans_message('blog_cms.checklist.title'),
                $title !== '' && mb_strlen($title) >= self::TITLE_MIN_LENGTH && mb_strlen($title) <= self::TITLE_MAX_LENGTH,
                trans_message('blog_cms.checklist.title_hint'),
            ),
            $this->item(
                'slug_unique',
                trans_message('blog_cms.checklist.slug'),
                $slug !== '' && $this->slugIsUnique($slug, $ignoreArticleId),
                trans_message('blog_cms.checklist.slug_hint'),
            ),
            $this->item(
                'excerpt',
                trans_message('blog_cms.checklist.excerpt'),
                $excerpt !== '' && mb_strlen($excerpt) <= self::EXCERPT_MAX_LENGTH,
                trans_message('blog_cms.checklist.excerpt_hint'),
            ),
            $this->item(
                'cover_image',
                trans_message('blog_cms.checklist.cover'),
                $featuredImage !== '',
                trans_message('blog_cms.checklist.cover_hint'),
            ),
            $this->item(
                'cover_alt',
                trans_message('blog_cms.checklist.cover_alt'),
                $featuredImage !== '' && $this->coverHasAltText($featuredImage),
                trans_message('blog_cms.checklist.cover_alt_hint'),
            ),
            $this->item(
                'category',
                trans_message('blog_cms.checklist.category'),
                filled(Arr::get($state, 'category_id')),
                trans_message('blog_cms.checklist.category_hint'),
            ),
            $this->item(
                'author',
                trans_message('blog_cms.checklist.author'),
                filled(Arr::get($state, 'author_system_admin_id')) || filled(Arr::get($state, 'author_id')),
                trans_message('blog_cms.checklist.author_hint'),
            ),
            $this->item(
                'seo_title',
                trans_message('blog_cms.checklist.seo_title'),
                $metaTitle !== '' && mb_strlen($metaTitle) <= self::TITLE_MAX_LENGTH,
                trans_message('blog_cms.checklist.seo_title_hint'),
            ),
            $this->item(
                'seo_description',
                trans_message('blog_cms.checklist.seo_description'),
                $metaDescription !== '' && mb_strlen($metaDescription) <= self::SEO_DESCRIPTION_MAX_LENGTH,
                trans_message('blog_cms.checklist.seo_description_hint'),
            ),
            $this->item(
                'canonical_url',
                trans_message('blog_cms.checklist.canonical_url'),
                $canonicalUrl === '' || $this->isHttpUrl($canonicalUrl),
                trans_message('blog_cms.checklist.canonical_url_hint'),
            ),
            $this->item(
                'body_minimum_text',
                trans_message('blog_cms.checklist.body_text'),
                $this->wordCount($content) >= self::BODY_MIN_WORDS,
                trans_message('blog_cms.checklist.body_text_hint'),
            ),
            $this->item(
                'body_headings',
                trans_message('blog_cms.checklist.body_headings'),
                $this->hasHeading($document, $content),
                trans_message('blog_cms.checklist.body_headings_hint'),
            ),
            $this->item(
                'scheduled_at',
                trans_message('blog_cms.checklist.scheduled_at'),
                $status !== BlogArticleStatusEnum::SCHEDULED->value || ($scheduledAt !== null && $scheduledAt->greaterThan(now())),
                trans_message('blog_cms.checklist.scheduled_at_hint'),
            ),
        ];

        $passed = collect($items)->where('passed', true)->count();
        $requiredItems = collect($items)->where('required', true);
        $blockingKeys = $requiredItems
            ->where('passed', false)
            ->pluck('key')
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => count($items),
            'passed' => $passed,
            'required_total' => $requiredItems->count(),
            'required_passed' => $requiredItems->where('passed', true)->count(),
            'can_publish' => $blockingKeys === [],
            'blocking_keys' => $blockingKeys,
        ];
    }

    public function assertCanPublish(BlogArticle|array $source, ?int $ignoreArticleId = null): void
    {
        $result = $this->evaluate($source, $ignoreArticleId);

        if ($result['can_publish']) {
            return;
        }

        throw ValidationException::withMessages([
            'editorial_checklist' => [trans_message('blog_cms.editorial_checklist_blocked')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(BlogArticle|array $source): array
    {
        if (is_array($source)) {
            return $source;
        }

        return [
            'id' => $source->id,
            'title' => $source->title,
            'slug' => $source->slug,
            'excerpt' => $source->excerpt,
            'canonical_url' => $source->canonical_url,
            'content' => $source->content,
            'editor_document' => $source->editor_document ?? [],
            'featured_image' => $source->featured_image,
            'category_id' => $source->category_id,
            'author_id' => $source->author_id,
            'author_system_admin_id' => $source->author_system_admin_id,
            'meta_title' => $source->meta_title,
            'meta_description' => $source->meta_description,
            'status' => $source->status,
            'scheduled_at' => $source->scheduled_at,
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, required: bool, message: string}
     */
    private function item(string $key, string $label, bool $passed, string $message, bool $required = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'required' => $required,
            'message' => $message,
        ];
    }

    private function slugIsUnique(string $slug, ?int $ignoreArticleId): bool
    {
        return ! BlogArticle::query()
            ->when($ignoreArticleId !== null, fn ($query) => $query->where('id', '!=', $ignoreArticleId))
            ->where('slug', $slug)
            ->exists();
    }

    private function coverHasAltText(string $featuredImage): bool
    {
        return BlogMediaAsset::query()
            ->where('public_url', $featuredImage)
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->exists();
    }

    private function hasHeading(array $document, string $content): bool
    {
        $hasHeadingBlock = collect($document)
            ->contains(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'heading' && filled(Arr::get($block, 'data.content')));

        return $hasHeadingBlock || Str::contains($content, ['<h2', '<h3', '<h4']);
    }

    private function wordCount(string $content): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($content), $matches);

        return count($matches[0] ?? []);
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value->toDateTime());
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
