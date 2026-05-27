<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogArticleRevision;
use App\Models\Blog\BlogSeoSettings;
use App\Models\Blog\BlogTag;
use App\Models\SystemAdmin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BlogCmsService
{
    public function __construct(
        private readonly BlogDocumentRenderer $documentRenderer,
        private readonly BlogEditorialChecklistService $editorialChecklist,
    ) {
    }

    public function createDraft(array $data, SystemAdmin $systemAdmin): BlogArticle
    {
        return DB::transaction(function () use ($data, $systemAdmin): BlogArticle {
            $article = BlogArticle::query()->create($this->prepareArticlePayload($data, $systemAdmin));
            $this->syncTags($article, Arr::wrap($data['tag_ids'] ?? $data['tags'] ?? []));
            $article = $article->fresh(['category', 'tags', 'systemAuthor']);
            $this->createRevision($article, BlogRevisionTypeEnum::MANUAL, $systemAdmin);

            return $article;
        });
    }

    public function updateArticle(BlogArticle $article, array $data, SystemAdmin $systemAdmin, BlogRevisionTypeEnum $revisionType = BlogRevisionTypeEnum::MANUAL): BlogArticle
    {
        return DB::transaction(function () use ($article, $data, $systemAdmin, $revisionType): BlogArticle {
            $article->fill($this->prepareArticlePayload($data, $systemAdmin, $article));
            $article->save();

            if (array_key_exists('tag_ids', $data) || array_key_exists('tags', $data)) {
                $this->syncTags($article, Arr::wrap($data['tag_ids'] ?? $data['tags'] ?? []));
            }

            $article = $article->fresh(['category', 'tags', 'systemAuthor']);
            $this->createRevision($article, $revisionType, $systemAdmin);

            return $article;
        });
    }

    public function autosaveArticle(BlogArticle $article, array $data, SystemAdmin $systemAdmin): BlogArticle
    {
        $data['last_autosaved_at'] = now();

        return $this->updateArticle($article, $data, $systemAdmin, BlogRevisionTypeEnum::AUTOSAVE);
    }

    public function publishArticle(BlogArticle $article, SystemAdmin $systemAdmin, ?string $publishAt = null): BlogArticle
    {
        $this->validateForPublish($article);
        $this->editorialChecklist->assertCanPublish($article);

        return $this->updateArticle($article, [
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => $publishAt ? CarbonImmutable::parse($publishAt) : now(),
            'scheduled_at' => null,
        ], $systemAdmin, BlogRevisionTypeEnum::PUBLISH);
    }

    public function scheduleArticle(BlogArticle $article, SystemAdmin $systemAdmin, string $scheduledAt): BlogArticle
    {
        return $this->updateArticle($article, [
            'status' => BlogArticleStatusEnum::SCHEDULED->value,
            'scheduled_at' => CarbonImmutable::parse($scheduledAt),
            'published_at' => null,
        ], $systemAdmin);
    }

    public function draftArticle(BlogArticle $article, SystemAdmin $systemAdmin): BlogArticle
    {
        return $this->updateArticle($article, [
            'status' => BlogArticleStatusEnum::DRAFT->value,
            'published_at' => null,
            'scheduled_at' => null,
        ], $systemAdmin);
    }

    public function archiveArticle(BlogArticle $article, SystemAdmin $systemAdmin): BlogArticle
    {
        return $this->updateArticle($article, [
            'status' => BlogArticleStatusEnum::ARCHIVED->value,
            'scheduled_at' => null,
        ], $systemAdmin);
    }

    public function duplicateArticle(BlogArticle $article, SystemAdmin $systemAdmin): BlogArticle
    {
        $tagIds = $article->tags()->pluck('blog_tags.id')->all();

        return $this->createDraft([
            'category_id' => $article->category_id,
            'author_id' => $article->author_id,
            'title' => $article->title . ' (' . trans_message('blog_cms.duplicate_suffix') . ')',
            'slug' => $this->generateUniqueSlug($article->slug . '-copy', $article->id),
            'excerpt' => $article->excerpt,
            'canonical_url' => null,
            'editor_notes' => $article->editor_notes,
            'editor_document' => $article->editor_document,
            'featured_image' => $article->featured_image,
            'gallery_images' => $article->gallery_images,
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'meta_keywords' => $article->meta_keywords,
            'og_title' => $article->og_title,
            'og_description' => $article->og_description,
            'og_image' => $article->og_image,
            'is_featured' => false,
            'allow_comments' => $article->allow_comments,
            'is_published_in_rss' => $article->is_published_in_rss,
            'noindex' => $article->noindex,
            'sort_order' => $article->sort_order,
            'tag_ids' => $tagIds,
        ], $systemAdmin);
    }

    public function restoreRevision(BlogArticleRevision $revision, SystemAdmin $systemAdmin): BlogArticle
    {
        return $this->updateArticle($revision->article, [
            'category_id' => $revision->category_id,
            'title' => $revision->title,
            'slug' => $revision->slug,
            'excerpt' => $revision->excerpt,
            'canonical_url' => $revision->canonical_url,
            'editor_notes' => $revision->editor_notes,
            'editor_document' => $revision->editor_document,
            'featured_image' => $revision->featured_image,
            'gallery_images' => $revision->gallery_images,
            'meta_title' => $revision->meta_title,
            'meta_description' => $revision->meta_description,
            'meta_keywords' => $revision->meta_keywords,
            'og_title' => $revision->og_title,
            'og_description' => $revision->og_description,
            'og_image' => $revision->og_image,
            'structured_data' => $revision->structured_data,
            'status' => $revision->status,
            'published_at' => $revision->published_at,
            'scheduled_at' => $revision->scheduled_at,
            'is_featured' => $revision->is_featured,
            'allow_comments' => $revision->allow_comments,
            'is_published_in_rss' => $revision->is_published_in_rss,
            'noindex' => $revision->noindex,
            'sort_order' => $revision->sort_order,
            'tag_ids' => $revision->tag_ids ?? [],
        ], $systemAdmin, BlogRevisionTypeEnum::RESTORE);
    }

    public function makePreviewUrl(BlogArticle $article): string
    {
        $signedUrl = URL::temporarySignedRoute(
            'api.v1.blog.preview',
            now()->addMinutes((int) config('blog.preview_ttl_minutes', 30)),
            ['article' => $article->id],
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $base = rtrim((string) config('blog.marketing_frontend_url'), '/');

        return $base . '/blog/preview/' . $article->id . ($query ? '?' . $query : '');
    }

    public function validateForPublish(BlogArticle $article): void
    {
        $errors = [];

        if ($article->title === '') {
            $errors['title'] = [trans_message('blog_cms.publish_title_required')];
        }

        if ($article->slug === '') {
            $errors['slug'] = [trans_message('blog_cms.publish_slug_required')];
        }

        if ($article->category_id === null) {
            $errors['category_id'] = [trans_message('blog_cms.publish_category_required')];
        }

        if (blank($article->content)) {
            $errors['content'] = [trans_message('blog_cms.publish_content_required')];
        }

        if (blank($article->featured_image)) {
            $errors['featured_image'] = [trans_message('blog_cms.publish_featured_image_required')];
        }

        if (blank($article->meta_title) || blank($article->meta_description)) {
            $errors['seo'] = [trans_message('blog_cms.publish_seo_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function prepareArticlePayload(array $data, SystemAdmin $systemAdmin, ?BlogArticle $article = null): array
    {
        $editorDocument = array_key_exists('editor_document', $data)
            ? Arr::wrap($data['editor_document'])
            : ($article?->editor_document ?? []);
        $content = $this->documentRenderer->render($editorDocument);
        $title = trim((string) ($data['title'] ?? $article?->title ?? ''));
        $slugWasProvided = array_key_exists('slug', $data);
        $titleWasProvided = array_key_exists('title', $data);
        $manualSlug = $slugWasProvided && trim((string) $data['slug']) !== '';
        $slug = trim((string) ($data['slug'] ?? $article?->slug ?? ''));

        if ($manualSlug) {
            $slug = Str::slug($slug);
        } elseif (
            ! $slugWasProvided
            && $titleWasProvided
            && $article !== null
            && $slug !== ''
            && $slug === Str::slug((string) $article->title)
        ) {
            $slug = $this->generateUniqueSlug(Str::slug($title), $article->id);
        } elseif ($slug === '' && $title !== '') {
            $slug = $this->generateUniqueSlug(Str::slug($title), $article?->id);
        }

        $seoSettings = BlogSeoSettings::getInstance(BlogContextEnum::MARKETING);
        $metaDescription = Arr::get($data, 'meta_description', $article?->meta_description);

        if (blank($metaDescription) && $seoSettings->auto_generate_meta_description) {
            $metaDescription = Str::limit(
                strip_tags((string) (Arr::get($data, 'excerpt', $article?->excerpt) ?: $content)),
                $seoSettings->meta_description_length,
            );
        }

        $payload = [
            'blog_context' => BlogContextEnum::MARKETING,
            'category_id' => Arr::get($data, 'category_id', $article?->category_id),
            'author_id' => Arr::get($data, 'author_id', $article?->author_id),
            'author_system_admin_id' => Arr::get($data, 'author_system_admin_id', $article?->author_system_admin_id ?? $systemAdmin->id),
            'last_edited_by_system_admin_id' => $systemAdmin->id,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => Arr::get($data, 'excerpt', $article?->excerpt),
            'canonical_url' => $this->normalizeNullableString(Arr::get($data, 'canonical_url', $article?->canonical_url)),
            'editor_notes' => Arr::get($data, 'editor_notes', $article?->editor_notes),
            'content' => $content,
            'editor_document' => $editorDocument,
            'editor_version' => ($article?->editor_version ?? 0) + 1,
            'featured_image' => Arr::get($data, 'featured_image', $article?->featured_image),
            'gallery_images' => Arr::get($data, 'gallery_images', $article?->gallery_images ?? []),
            'meta_title' => Arr::get($data, 'meta_title', $article?->meta_title ?: $title),
            'meta_description' => $metaDescription,
            'meta_keywords' => Arr::get($data, 'meta_keywords', $article?->meta_keywords ?? []),
            'og_title' => Arr::get($data, 'og_title', $article?->og_title ?: $title),
            'og_description' => Arr::get($data, 'og_description', $article?->og_description ?: $metaDescription),
            'og_image' => Arr::get($data, 'og_image', $article?->og_image ?: Arr::get($data, 'featured_image', $article?->featured_image)),
            'structured_data' => $this->buildStructuredData($data, $article, $systemAdmin, $title, $slug, $content),
            'status' => Arr::get($data, 'status', $article?->status?->value ?? BlogArticleStatusEnum::DRAFT->value),
            'published_at' => Arr::get($data, 'published_at', $article?->published_at),
            'scheduled_at' => Arr::get($data, 'scheduled_at', $article?->scheduled_at),
            'reading_time' => $this->documentRenderer->estimateReadingTime($content),
            'is_featured' => (bool) Arr::get($data, 'is_featured', $article?->is_featured ?? false),
            'allow_comments' => (bool) Arr::get($data, 'allow_comments', $article?->allow_comments ?? true),
            'is_published_in_rss' => (bool) Arr::get($data, 'is_published_in_rss', $article?->is_published_in_rss ?? true),
            'noindex' => (bool) Arr::get($data, 'noindex', $article?->noindex ?? false),
            'sort_order' => (int) Arr::get($data, 'sort_order', $article?->sort_order ?? 0),
            'last_autosaved_at' => Arr::get($data, 'last_autosaved_at', $article?->last_autosaved_at),
        ];

        $this->validateEditorPayload($payload, $article, $manualSlug);

        return $payload;
    }

    private function buildStructuredData(array $data, ?BlogArticle $article, SystemAdmin $systemAdmin, string $title, string $slug, string $content): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $title,
            'description' => Arr::get($data, 'meta_description', $article?->meta_description ?: Str::limit(strip_tags($content), 160)),
            'image' => Arr::get($data, 'og_image', $article?->og_image ?: Arr::get($data, 'featured_image', $article?->featured_image)),
            'author' => [
                '@type' => 'Person',
                'name' => $article?->author_label ?? $systemAdmin->name,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
            'datePublished' => $article?->published_at?->toISOString(),
            'dateModified' => now()->toISOString(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => Arr::get($data, 'canonical_url', $article?->canonical_url)
                    ?: rtrim((string) config('blog.marketing_frontend_url'), '/') . '/blog/' . $slug,
            ],
        ];
    }

    private function createRevision(BlogArticle $article, BlogRevisionTypeEnum $revisionType, SystemAdmin $systemAdmin): BlogArticleRevision
    {
        $article->loadMissing(['category', 'tags']);

        return $article->revisions()->create([
            'blog_context' => BlogContextEnum::MARKETING,
            'revision_type' => $revisionType,
            'editor_version' => $article->editor_version,
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt,
            'canonical_url' => $article->canonical_url,
            'editor_notes' => $article->editor_notes,
            'content_html' => $article->content,
            'editor_document' => $article->editor_document,
            'featured_image' => $article->featured_image,
            'gallery_images' => $article->gallery_images,
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'meta_keywords' => $article->meta_keywords,
            'og_title' => $article->og_title,
            'og_description' => $article->og_description,
            'og_image' => $article->og_image,
            'structured_data' => $article->structured_data,
            'category_id' => $article->category_id,
            'category_snapshot' => $article->category ? [
                'id' => $article->category->id,
                'name' => $article->category->name,
                'slug' => $article->category->slug,
            ] : null,
            'tag_ids' => $article->tags->pluck('id')->values()->all(),
            'tags_snapshot' => $article->tags->map(fn (BlogTag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'status' => $article->status->value,
            'published_at' => $article->published_at,
            'scheduled_at' => $article->scheduled_at,
            'is_featured' => $article->is_featured,
            'allow_comments' => $article->allow_comments,
            'is_published_in_rss' => $article->is_published_in_rss,
            'noindex' => $article->noindex,
            'sort_order' => $article->sort_order,
            'created_by_system_admin_id' => $systemAdmin->id,
        ]);
    }

    private function syncTags(BlogArticle $article, array $tags): void
    {
        $previousTagIds = $article->tags()->pluck('blog_tags.id')->all();
        $tagIds = collect($tags)
            ->map(function (mixed $tag): ?int {
                if (is_numeric($tag)) {
                    return (int) $tag;
                }

                if (is_string($tag) && $tag !== '') {
                    return BlogTag::query()->firstOrCreate(
                        ['slug' => Str::slug($tag)],
                        [
                            'blog_context' => BlogContextEnum::MARKETING,
                            'name' => $tag,
                            'is_active' => true,
                        ],
                    )->id;
                }

                return null;
            })
            ->filter()
            ->values();

        $article->tags()->sync($tagIds->all());

        BlogTag::query()
            ->whereIn('id', array_values(array_unique(array_merge($previousTagIds, $tagIds->all()))))
            ->get()
            ->each(fn (BlogTag $tag): bool => $tag->update(['usage_count' => $tag->articles()->count()]));
    }

    private function generateUniqueSlug(string $slug, ?int $ignoreArticleId = null): string
    {
        $baseSlug = $slug === '' ? Str::random(8) : $slug;
        $candidate = $baseSlug;
        $counter = 2;

        while (
            BlogArticle::query()
                ->when($ignoreArticleId !== null, fn ($query) => $query->where('id', '!=', $ignoreArticleId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function validateEditorPayload(array $payload, ?BlogArticle $article, bool $manualSlug): void
    {
        $errors = [];
        $title = trim((string) ($payload['title'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));
        $excerpt = (string) ($payload['excerpt'] ?? '');
        $canonicalUrl = trim((string) ($payload['canonical_url'] ?? ''));
        $status = $payload['status'] instanceof BlogArticleStatusEnum
            ? $payload['status']->value
            : (string) ($payload['status'] ?? BlogArticleStatusEnum::DRAFT->value);

        if (mb_strlen($title) < 3) {
            $errors['title'] = [trans_message('blog_cms.validation_title_min')];
        }

        if (mb_strlen($title) > 255) {
            $errors['title'] = [trans_message('blog_cms.validation_title_max')];
        }

        if ($slug === '') {
            $errors['slug'] = [trans_message('blog_cms.publish_slug_required')];
        } elseif ($manualSlug && $this->slugExists($slug, $article?->id)) {
            $errors['slug'] = [trans_message('blog_cms.validation_slug_unique')];
        }

        if (mb_strlen($excerpt) > 500) {
            $errors['excerpt'] = [trans_message('blog_cms.validation_excerpt_max')];
        }

        if ($canonicalUrl !== '' && ! $this->isHttpUrl($canonicalUrl)) {
            $errors['canonical_url'] = [trans_message('blog_cms.validation_canonical_url')];
        }

        if (BlogArticleStatusEnum::tryFrom($status) === null) {
            $errors['status'] = [trans_message('blog_cms.validation_status')];
        }

        $scheduledAt = $this->parseDateTime($payload['scheduled_at'] ?? null);

        if ($status === BlogArticleStatusEnum::SCHEDULED->value) {
            if ($scheduledAt === null) {
                $errors['scheduled_at'] = [trans_message('blog_cms.validation_schedule_required')];
            } elseif ($scheduledAt->lessThanOrEqualTo(now())) {
                $errors['scheduled_at'] = [trans_message('blog_cms.validation_schedule_future')];
            }
        }

        $publishedAt = $this->parseDateTime($payload['published_at'] ?? null);

        if ($status === BlogArticleStatusEnum::PUBLISHED->value && $publishedAt?->greaterThan(now()->addMinute())) {
            $errors['published_at'] = [trans_message('blog_cms.validation_publish_date')];
        }

        if (in_array($status, [BlogArticleStatusEnum::PUBLISHED->value, BlogArticleStatusEnum::SCHEDULED->value], true)) {
            $this->validatePublishablePayload($payload, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (in_array($status, [BlogArticleStatusEnum::PUBLISHED->value, BlogArticleStatusEnum::SCHEDULED->value], true)) {
            $this->editorialChecklist->assertCanPublish($payload, $article?->id);
        }
    }

    private function validatePublishablePayload(array $payload, array &$errors): void
    {
        if (($payload['title'] ?? '') === '') {
            $errors['title'] = [trans_message('blog_cms.publish_title_required')];
        }

        if (($payload['slug'] ?? '') === '') {
            $errors['slug'] = [trans_message('blog_cms.publish_slug_required')];
        }

        if (($payload['category_id'] ?? null) === null) {
            $errors['category_id'] = [trans_message('blog_cms.publish_category_required')];
        }

        if (blank($payload['content'] ?? null)) {
            $errors['content'] = [trans_message('blog_cms.publish_content_required')];
        }

        if (blank($payload['featured_image'] ?? null)) {
            $errors['featured_image'] = [trans_message('blog_cms.publish_featured_image_required')];
        }

        if (blank($payload['meta_title'] ?? null) || blank($payload['meta_description'] ?? null)) {
            $errors['seo'] = [trans_message('blog_cms.publish_seo_required')];
        }
    }

    private function slugExists(string $slug, ?int $ignoreArticleId = null): bool
    {
        return BlogArticle::query()
            ->when($ignoreArticleId !== null, fn ($query) => $query->where('id', '!=', $ignoreArticleId))
            ->where('slug', $slug)
            ->exists();
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

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
