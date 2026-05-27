<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogEditorialOperationsService;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class BlogArticleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->marketing()->with(['category', 'systemAuthor']))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(trans_message('blog_cms.article_title_column'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('calendar_date')
                    ->label(trans_message('blog_cms.calendar_date_column'))
                    ->getStateUsing(fn (BlogArticle $record): mixed => app(BlogEditorialOperationsService::class)
                        ->calendarDateFor($record))
                    ->dateTime()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(trans_message('blog_cms.article_category_column'))
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('blog_cms.article_status_column'))
                    ->formatStateUsing(fn (?BlogArticleStatusEnum $state): string => $state !== null
                        ? trans_message('blog_cms.article_statuses.' . $state->value)
                        : '')
                    ->badge(),
                Tables\Columns\TextColumn::make('systemAuthor.name')
                    ->label(trans_message('blog_cms.article_author_column'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(trans_message('blog_cms.article_published_at_column'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_autosaved_at')
                    ->label(trans_message('blog_cms.article_autosaved_at_column'))
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('blog_cms.article_status_column'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(trans_message('blog_cms.article_category_column'))
                    ->options(fn (): array => BlogCategory::query()
                        ->marketing()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Tables\Filters\SelectFilter::make('author_system_admin_id')
                    ->label(trans_message('blog_cms.article_author_column'))
                    ->options(fn (): array => SystemAdmin::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Tables\Filters\Filter::make('calendar_date')
                    ->label(trans_message('blog_cms.calendar_date_filter'))
                    ->schema([
                        DatePicker::make('date_from')
                            ->label(trans_message('blog_cms.calendar_date_from')),
                        DatePicker::make('date_to')
                            ->label(trans_message('blog_cms.calendar_date_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => app(BlogEditorialOperationsService::class)
                        ->applyCalendarDateFilter($query, $data)),
            ])
            ->actions([
                ViewAction::make()->label(trans_message('blog_cms.article_view_action')),
                EditAction::make()->label(trans_message('blog_cms.article_edit_action')),
                BlogArticleResource::guardedArticleDeleteAction(),
            ])
            ->bulkActions([
                BulkAction::make('assign_category')
                    ->label(trans_message('blog_cms.calendar_assign_category_action'))
                    ->icon('heroicon-o-folder')
                    ->schema([
                        Select::make('category_id')
                            ->label(trans_message('blog_cms.article_category_column'))
                            ->options(fn (): array => BlogCategory::query()
                                ->marketing()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $count = app(BlogEditorialOperationsService::class)->assignCategory(
                            $records,
                            (int) $data['category_id'],
                            self::currentSystemAdmin(),
                        );
                        self::notify('blog_cms.calendar_assign_category_done', $count);
                    }),
                BulkAction::make('assign_author')
                    ->label(trans_message('blog_cms.calendar_assign_author_action'))
                    ->icon('heroicon-o-user')
                    ->schema([
                        Select::make('author_system_admin_id')
                            ->label(trans_message('blog_cms.article_author_column'))
                            ->options(fn (): array => SystemAdmin::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $count = app(BlogEditorialOperationsService::class)->assignSystemAuthor(
                            $records,
                            (int) $data['author_system_admin_id'],
                            self::currentSystemAdmin(),
                        );
                        self::notify('blog_cms.calendar_assign_author_done', $count);
                    }),
                BulkAction::make('move_scheduled_date')
                    ->label(trans_message('blog_cms.calendar_move_schedule_action'))
                    ->icon('heroicon-o-calendar-days')
                    ->requiresConfirmation()
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label(trans_message('blog_cms.field_scheduled_at'))
                            ->required()
                            ->rules(['required', 'date', 'after:now']),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $count = app(BlogEditorialOperationsService::class)->moveScheduledDate(
                            $records,
                            (string) $data['scheduled_at'],
                            self::currentSystemAdmin(),
                        );
                        self::notify('blog_cms.calendar_move_schedule_done', $count);
                    }),
                BulkAction::make('archive_drafts')
                    ->label(trans_message('blog_cms.calendar_archive_drafts_action'))
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('blog_cms.calendar_archive_drafts_heading'))
                    ->modalDescription(trans_message('blog_cms.calendar_archive_drafts_description'))
                    ->modalSubmitActionLabel(trans_message('blog_cms.calendar_archive_drafts_confirm'))
                    ->action(function (Collection $records): void {
                        $count = app(BlogEditorialOperationsService::class)->archiveDrafts(
                            $records,
                            self::currentSystemAdmin(),
                        );
                        self::notify('blog_cms.calendar_archive_drafts_done', $count);
                    }),
            ]);
    }

    private static function currentSystemAdmin(): SystemAdmin
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if (! $systemAdmin instanceof SystemAdmin) {
            throw new RuntimeException('System admin is required for blog bulk operations.');
        }

        return $systemAdmin;
    }

    private static function notify(string $translationKey, int $count): void
    {
        Notification::make()
            ->success()
            ->title(trans_message($translationKey, ['count' => $count]))
            ->send();
    }

    private static function statusOptions(): array
    {
        return collect(BlogArticleStatusEnum::cases())
            ->mapWithKeys(fn (BlogArticleStatusEnum $status): array => [
                $status->value => trans_message('blog_cms.article_statuses.' . $status->value),
            ])
            ->all();
    }
}
