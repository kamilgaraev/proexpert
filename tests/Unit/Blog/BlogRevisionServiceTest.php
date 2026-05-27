<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogArticleRevision;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\BlogArticleRevisionPolicy;
use App\Services\Blog\BlogRevisionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogRevisionServiceTest extends TestCase
{
    private SystemAdmin $systemAdmin;

    private LandingAdmin $landingAdmin;

    private BlogCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemAdmin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);

        $this->landingAdmin = LandingAdmin::query()->create([
            'name' => 'Content Editor',
            'email' => 'content-editor-' . $this->systemAdmin->id . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->category = BlogCategory::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Product',
            'slug' => 'product',
            'is_active' => true,
        ]);
    }

    public function test_it_creates_diff_friendly_revision_snapshot(): void
    {
        $article = $this->articleFixture('current-article');
        $tag = BlogTag::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Guides',
            'slug' => 'guides',
            'is_active' => true,
        ]);
        $article->tags()->sync([$tag->id]);
        $article->load(['category', 'tags', 'systemAuthor']);

        $revision = app(BlogRevisionService::class)->createSnapshot(
            $article,
            BlogRevisionTypeEnum::MANUAL,
            $this->systemAdmin,
        );

        $this->assertSame($article->title, $revision->title);
        $this->assertSame($article->slug, $revision->slug);
        $this->assertSame($article->status->value, $revision->status);
        $this->assertSame($article->meta_title, $revision->meta_title);
        $this->assertSame($article->meta_description, $revision->meta_description);
        $this->assertSame(hash('sha256', (string) $article->content), $revision->body_hash);
        $this->assertSame($this->systemAdmin->id, $revision->created_by_system_admin_id);
        $this->assertSame($this->category->id, $revision->category_snapshot['id']);
        $this->assertSame([$tag->id], $revision->tag_ids);
        $this->assertSame($tag->slug, $revision->tags_snapshot[0]['slug']);
    }

    public function test_it_restores_revision_only_for_draft_articles(): void
    {
        $article = $this->articleFixture('restore-current');
        $tag = BlogTag::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Restore',
            'slug' => 'restore',
            'is_active' => true,
        ]);
        $revision = $this->revisionFixture($article, $tag);

        $restored = app(BlogRevisionService::class)->restoreRevision($revision, $this->systemAdmin);

        $this->assertSame('Restored article', $restored->title);
        $this->assertSame('restored-article', $restored->slug);
        $this->assertSame('<p>Restored body</p>', $restored->content);
        $this->assertSame(BlogArticleStatusEnum::DRAFT, $restored->status);
        $this->assertNull($restored->published_at);
        $this->assertNull($restored->scheduled_at);
        $this->assertSame([$tag->id], $restored->tags()->pluck('blog_tags.id')->all());
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $article->id,
            'revision_type' => BlogRevisionTypeEnum::RESTORE->value,
            'title' => 'Restored article',
        ]);

        $restored->forceFill([
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => now(),
        ])->save();

        try {
            app(BlogRevisionService::class)->restoreRevision($revision->fresh(), $this->systemAdmin);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
    }

    public function test_restore_policy_allows_only_draft_article_revisions(): void
    {
        $article = $this->articleFixture('restore-policy-current');
        $tag = BlogTag::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Policy',
            'slug' => 'policy',
            'is_active' => true,
        ]);
        $revision = $this->revisionFixture($article, $tag);
        $policy = app(BlogArticleRevisionPolicy::class);

        $this->assertTrue($policy->restore($this->systemAdmin, $revision));

        $article->forceFill([
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => now(),
        ])->save();
        $revision->unsetRelation('article');

        $this->assertFalse($policy->restore($this->systemAdmin, $revision));
    }

    private function articleFixture(string $slug): BlogArticle
    {
        return BlogArticle::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $this->category->id,
            'author_id' => $this->landingAdmin->id,
            'author_system_admin_id' => $this->systemAdmin->id,
            'last_edited_by_system_admin_id' => $this->systemAdmin->id,
            'title' => 'Current article',
            'slug' => $slug,
            'excerpt' => 'Current excerpt',
            'canonical_url' => 'https://example.test/blog/' . $slug,
            'editor_notes' => 'Internal editorial note',
            'content' => '<p>Current body</p>',
            'editor_document' => [
                [
                    'type' => 'paragraph',
                    'data' => ['content' => 'Current body'],
                ],
            ],
            'editor_version' => 3,
            'featured_image' => 'https://cdn.example.test/blog/current.jpg',
            'gallery_images' => ['https://cdn.example.test/blog/gallery.jpg'],
            'meta_title' => 'Current SEO title',
            'meta_description' => 'Current SEO description',
            'meta_keywords' => ['current', 'seo'],
            'og_title' => 'Current OG title',
            'og_description' => 'Current OG description',
            'og_image' => 'https://cdn.example.test/blog/og.jpg',
            'structured_data' => ['@type' => 'BlogPosting'],
            'status' => BlogArticleStatusEnum::DRAFT->value,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ]);
    }

    private function revisionFixture(BlogArticle $article, BlogTag $tag): BlogArticleRevision
    {
        return $article->revisions()->create([
            'blog_context' => BlogContextEnum::MARKETING,
            'revision_type' => BlogRevisionTypeEnum::MANUAL,
            'editor_version' => 2,
            'title' => 'Restored article',
            'slug' => 'restored-article',
            'excerpt' => 'Restored excerpt',
            'canonical_url' => 'https://example.test/blog/restored-article',
            'editor_notes' => 'Restored note',
            'content_html' => '<p>Restored body</p>',
            'body_hash' => hash('sha256', '<p>Restored body</p>'),
            'editor_document' => [
                [
                    'type' => 'paragraph',
                    'data' => ['content' => 'Restored body'],
                ],
            ],
            'featured_image' => 'https://cdn.example.test/blog/restored.jpg',
            'gallery_images' => ['https://cdn.example.test/blog/restored-gallery.jpg'],
            'meta_title' => 'Restored SEO title',
            'meta_description' => 'Restored SEO description',
            'meta_keywords' => ['restored', 'seo'],
            'og_title' => 'Restored OG title',
            'og_description' => 'Restored OG description',
            'og_image' => 'https://cdn.example.test/blog/restored-og.jpg',
            'structured_data' => ['@type' => 'BlogPosting'],
            'category_id' => $this->category->id,
            'category_snapshot' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
            'tag_ids' => [$tag->id],
            'tags_snapshot' => [
                [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ],
            ],
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => now()->subDay(),
            'scheduled_at' => null,
            'is_featured' => true,
            'allow_comments' => false,
            'is_published_in_rss' => false,
            'noindex' => true,
            'sort_order' => 12,
            'created_by_system_admin_id' => $this->systemAdmin->id,
        ]);
    }
}
