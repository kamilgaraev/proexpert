<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogArticleRevision;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use App\Models\SystemAdmin;
use App\Services\Filament\SystemAdminAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlogRevisionService
{
    public function __construct(
        private readonly BlogDocumentRenderer $documentRenderer,
        private readonly SystemAdminAuditService $auditService,
    ) {
    }

    public function createSnapshot(
        BlogArticle $article,
        BlogRevisionTypeEnum $revisionType,
        SystemAdmin $systemAdmin,
    ): BlogArticleRevision {
        $article->loadMissing(['category', 'tags', 'author', 'systemAuthor']);

        return $article->revisions()->create($this->snapshotPayload($article, $revisionType, $systemAdmin));
    }

    public function restoreRevision(BlogArticleRevision $revision, SystemAdmin $systemAdmin): BlogArticle
    {
        return DB::transaction(function () use ($revision, $systemAdmin): BlogArticle {
            $revision->loadMissing('article');
            $article = $revision->article;

            if (! $article instanceof BlogArticle) {
                throw ValidationException::withMessages([
                    'revision' => [trans_message('blog_cms.revision_article_missing')],
                ]);
            }

            if ($article->status !== BlogArticleStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'revision' => [trans_message('blog_cms.revision_restore_draft_only')],
                ]);
            }

            $before = $this->snapshotForAudit($article);
            $revisionAuthorId = $revision->getAttribute('author_id');
            $revisionSystemAuthorId = $revision->getAttribute('author_system_admin_id');

            $article->forceFill([
                'category_id' => $this->resolveCategoryId($revision->category_id),
                'author_id' => $revisionAuthorId !== null ? (int) $revisionAuthorId : $article->author_id,
                'author_system_admin_id' => $revisionSystemAuthorId !== null
                    ? (int) $revisionSystemAuthorId
                    : $article->author_system_admin_id,
                'last_edited_by_system_admin_id' => $systemAdmin->id,
                'title' => $revision->title,
                'slug' => $revision->slug,
                'excerpt' => $revision->excerpt,
                'canonical_url' => $revision->canonical_url,
                'editor_notes' => $revision->editor_notes,
                'content' => (string) $revision->content_html,
                'editor_document' => $revision->editor_document ?? [],
                'editor_version' => ((int) $article->editor_version) + 1,
                'featured_image' => $revision->featured_image,
                'gallery_images' => $revision->gallery_images ?? [],
                'meta_title' => $revision->meta_title,
                'meta_description' => $revision->meta_description,
                'meta_keywords' => $revision->meta_keywords ?? [],
                'og_title' => $revision->og_title,
                'og_description' => $revision->og_description,
                'og_image' => $revision->og_image,
                'structured_data' => $revision->structured_data ?? [],
                'status' => BlogArticleStatusEnum::DRAFT->value,
                'published_at' => null,
                'scheduled_at' => null,
                'reading_time' => $this->documentRenderer->estimateReadingTime((string) $revision->content_html),
                'is_featured' => $revision->is_featured,
                'allow_comments' => $revision->allow_comments,
                'is_published_in_rss' => $revision->is_published_in_rss,
                'noindex' => $revision->noindex,
                'sort_order' => $revision->sort_order,
            ])->save();

            $this->syncTags($article, $revision->tag_ids ?? []);

            $article = $article->fresh(['category', 'tags', 'author', 'systemAuthor']);
            if (! $article instanceof BlogArticle) {
                throw ValidationException::withMessages([
                    'revision' => [trans_message('blog_cms.revision_article_missing')],
                ]);
            }

            $this->createSnapshot($article, BlogRevisionTypeEnum::RESTORE, $systemAdmin);

            $this->recordRestoreAudit($revision, $article, $systemAdmin, $before);

            return $article;
        });
    }

    public function changedFieldLabels(BlogArticleRevision $revision, ?BlogArticle $article = null): array
    {
        return array_map(
            static fn (string $field): string => trans_message('blog_cms.revision_fields.' . $field),
            $this->changedFieldKeys($revision, $article),
        );
    }

    public function changedFieldSummary(BlogArticleRevision $revision, ?BlogArticle $article = null): string
    {
        $labels = $this->changedFieldLabels($revision, $article);

        return $labels === []
            ? trans_message('blog_cms.revision_no_changes')
            : implode(', ', $labels);
    }

    public function changedFieldKeys(BlogArticleRevision $revision, ?BlogArticle $article = null): array
    {
        $article ??= $revision->article;
        if (! $article instanceof BlogArticle) {
            return [];
        }

        $article->loadMissing(['tags']);

        $fieldChecks = [
            'title' => (string) $revision->title !== (string) $article->title,
            'slug' => (string) $revision->slug !== (string) $article->slug,
            'status' => (string) $revision->status !== $article->status->value,
            'author' => (int) ($revision->author_id ?? 0) !== (int) ($article->author_id ?? 0)
                || (int) ($revision->author_system_admin_id ?? 0) !== (int) ($article->author_system_admin_id ?? 0),
            'category' => (int) ($revision->category_id ?? 0) !== (int) ($article->category_id ?? 0),
            'seo' => $this->seoSnapshot($revision) !== $this->seoSnapshot($article),
            'body' => (string) ($revision->body_hash ?? '') !== hash('sha256', (string) $article->content),
            'tags' => $this->normalizeIds($revision->tag_ids ?? [])
                !== $this->normalizeIds($article->tags->pluck('id')->all()),
            'publication' => $this->publicationSnapshot($revision) !== $this->publicationSnapshot($article),
        ];

        return array_keys(array_filter($fieldChecks));
    }

    private function snapshotPayload(
        BlogArticle $article,
        BlogRevisionTypeEnum $revisionType,
        SystemAdmin $systemAdmin,
    ): array {
        return [
            'blog_context' => BlogContextEnum::MARKETING,
            'revision_type' => $revisionType,
            'editor_version' => $article->editor_version,
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt,
            'canonical_url' => $article->canonical_url,
            'editor_notes' => $article->editor_notes,
            'content_html' => $article->content,
            'body_hash' => hash('sha256', (string) $article->content),
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
            'author_id' => $article->author_id,
            'author_system_admin_id' => $article->author_system_admin_id,
            'author_snapshot' => $this->authorSnapshot($article),
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
        ];
    }

    private function authorSnapshot(BlogArticle $article): ?array
    {
        if ($article->systemAuthor !== null) {
            return [
                'type' => 'system_admin',
                'id' => $article->systemAuthor->id,
                'name' => $article->systemAuthor->name,
                'email' => $article->systemAuthor->email,
            ];
        }

        if ($article->author !== null) {
            return [
                'type' => 'landing_admin',
                'id' => $article->author->id,
                'name' => $article->author->name,
                'email' => $article->author->email,
            ];
        }

        return null;
    }

    private function syncTags(BlogArticle $article, array $tagIds): void
    {
        $previousTagIds = $article->tags()->pluck('blog_tags.id')->all();
        $existingTagIds = BlogTag::query()
            ->whereIn('id', $this->normalizeIds($tagIds))
            ->pluck('id')
            ->all();

        $article->tags()->sync($existingTagIds);
        $affectedTagIds = array_values(array_unique(array_merge($previousTagIds, $existingTagIds)));

        if ($affectedTagIds === []) {
            return;
        }

        BlogTag::query()
            ->whereIn('id', $affectedTagIds)
            ->get()
            ->each(fn (BlogTag $tag): bool => $tag->update(['usage_count' => $tag->articles()->count()]));
    }

    private function resolveCategoryId(?int $categoryId): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return BlogCategory::query()->whereKey($categoryId)->exists() ? $categoryId : null;
    }

    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function seoSnapshot(BlogArticle|BlogArticleRevision $source): array
    {
        return [
            'meta_title' => $source->meta_title,
            'meta_description' => $source->meta_description,
            'meta_keywords' => $source->meta_keywords ?? [],
            'canonical_url' => $source->canonical_url,
            'og_title' => $source->og_title,
            'og_description' => $source->og_description,
            'og_image' => $source->og_image,
            'noindex' => (bool) $source->noindex,
        ];
    }

    private function publicationSnapshot(BlogArticle|BlogArticleRevision $source): array
    {
        return [
            'published_at' => $source->published_at?->toDateTimeString(),
            'scheduled_at' => $source->scheduled_at?->toDateTimeString(),
            'is_featured' => (bool) $source->is_featured,
            'is_published_in_rss' => (bool) $source->is_published_in_rss,
        ];
    }

    private function snapshotForAudit(BlogArticle $article): array
    {
        return [
            'title' => $article->title,
            'slug' => $article->slug,
            'status' => $article->status->value,
            'body_hash' => hash('sha256', (string) $article->content),
        ];
    }

    private function recordRestoreAudit(
        BlogArticleRevision $revision,
        BlogArticle $article,
        SystemAdmin $systemAdmin,
        array $before,
    ): void {
        $this->auditService->record(
            actor: $systemAdmin,
            eventType: 'system_admin.blog.revisions.restored',
            action: ActivityActionEnum::Updated,
            subjectType: BlogArticle::class,
            subjectId: $article->id,
            subjectLabel: $article->title,
            title: trans_message('blog_cms.revision_restore_audit_title'),
            description: trans_message('blog_cms.revision_restore_audit_description'),
            before: $before,
            after: [
                'title' => $article->title,
                'slug' => $article->slug,
                'status' => $article->status->value,
                'body_hash' => hash('sha256', (string) $article->content),
                'restored_revision_id' => $revision->id,
            ],
        );
    }
}
