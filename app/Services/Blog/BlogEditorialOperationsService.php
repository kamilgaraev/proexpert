<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\SystemAdmin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlogEditorialOperationsService
{
    public function __construct(
        private readonly BlogRevisionService $revisionService,
    ) {
    }

    public function calendarDateFor(BlogArticle $article): ?CarbonInterface
    {
        return $article->scheduled_at
            ?? $article->published_at
            ?? $article->updated_at
            ?? $article->created_at;
    }

    public function applyCalendarDateFilter(Builder $query, array $data): Builder
    {
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;

        return $query
            ->when(
                $dateFrom,
                fn (Builder $query, mixed $date): Builder => $this->whereCalendarDate($query, '>=', (string) $date),
            )
            ->when(
                $dateTo,
                fn (Builder $query, mixed $date): Builder => $this->whereCalendarDate($query, '<=', (string) $date),
            );
    }

    public function assignCategory(Collection $articles, int $categoryId, SystemAdmin $systemAdmin): int
    {
        $category = BlogCategory::query()->marketing()->find($categoryId);

        if (! $category instanceof BlogCategory) {
            throw ValidationException::withMessages([
                'category_id' => [trans_message('blog_cms.calendar_category_missing')],
            ]);
        }

        return DB::transaction(fn (): int => $this->applyToArticles(
            $articles,
            [
                'category_id' => $category->id,
                'last_edited_by_system_admin_id' => $systemAdmin->id,
            ],
            BlogRevisionTypeEnum::MANUAL,
            $systemAdmin,
        ));
    }

    public function assignSystemAuthor(Collection $articles, int $authorId, SystemAdmin $systemAdmin): int
    {
        $author = SystemAdmin::query()
            ->whereKey($authorId)
            ->where('is_active', true)
            ->first();

        if (! $author instanceof SystemAdmin) {
            throw ValidationException::withMessages([
                'author_system_admin_id' => [trans_message('blog_cms.calendar_author_missing')],
            ]);
        }

        return DB::transaction(fn (): int => $this->applyToArticles(
            $articles,
            [
                'author_system_admin_id' => $author->id,
                'last_edited_by_system_admin_id' => $systemAdmin->id,
            ],
            BlogRevisionTypeEnum::MANUAL,
            $systemAdmin,
        ));
    }

    public function moveScheduledDate(
        Collection $articles,
        CarbonInterface|string $scheduledAt,
        SystemAdmin $systemAdmin,
    ): int {
        $scheduledAt = $scheduledAt instanceof CarbonInterface
            ? CarbonImmutable::instance($scheduledAt->toDateTime())
            : CarbonImmutable::parse($scheduledAt);

        if ($scheduledAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => [trans_message('blog_cms.calendar_schedule_future')],
            ]);
        }

        $this->assertAllHaveStatus(
            $articles,
            BlogArticleStatusEnum::SCHEDULED,
            trans_message('blog_cms.calendar_only_scheduled'),
        );

        return DB::transaction(fn (): int => $this->applyToArticles(
            $articles,
            [
                'scheduled_at' => $scheduledAt,
                'published_at' => null,
                'last_edited_by_system_admin_id' => $systemAdmin->id,
            ],
            BlogRevisionTypeEnum::SCHEDULE,
            $systemAdmin,
        ));
    }

    public function archiveDrafts(Collection $articles, SystemAdmin $systemAdmin): int
    {
        $this->assertAllHaveStatus(
            $articles,
            BlogArticleStatusEnum::DRAFT,
            trans_message('blog_cms.calendar_only_drafts'),
        );

        return DB::transaction(fn (): int => $this->applyToArticles(
            $articles,
            [
                'status' => BlogArticleStatusEnum::ARCHIVED->value,
                'scheduled_at' => null,
                'published_at' => null,
                'last_edited_by_system_admin_id' => $systemAdmin->id,
            ],
            BlogRevisionTypeEnum::ARCHIVE,
            $systemAdmin,
        ));
    }

    private function whereCalendarDate(Builder $query, string $operator, string $date): Builder
    {
        return $query->where(function (Builder $query) use ($operator, $date): void {
            $query
                ->where(fn (Builder $query): Builder => $query
                    ->whereNotNull('scheduled_at')
                    ->whereDate('scheduled_at', $operator, $date))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNull('scheduled_at')
                    ->whereNotNull('published_at')
                    ->whereDate('published_at', $operator, $date))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNull('scheduled_at')
                    ->whereNull('published_at')
                    ->whereDate('updated_at', $operator, $date));
        });
    }

    private function assertAllHaveStatus(Collection $articles, BlogArticleStatusEnum $status, string $message): void
    {
        $hasInvalidArticle = $articles->contains(
            fn (BlogArticle $article): bool => $article->status !== $status,
        );

        if ($hasInvalidArticle) {
            throw ValidationException::withMessages([
                'articles' => [$message],
            ]);
        }
    }

    private function applyToArticles(
        Collection $articles,
        array $attributes,
        BlogRevisionTypeEnum $revisionType,
        SystemAdmin $systemAdmin,
    ): int {
        $count = 0;

        $articles->each(function (BlogArticle $article) use ($attributes, $revisionType, $systemAdmin, &$count): void {
            $article->forceFill($attributes);
            $article->save();

            $article = $article->fresh(['author', 'category', 'tags', 'systemAuthor']);

            if ($article instanceof BlogArticle) {
                $this->revisionService->createSnapshot($article, $revisionType, $systemAdmin);
                $count++;
            }
        });

        return $count;
    }
}
