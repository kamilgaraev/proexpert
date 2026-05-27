<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Pages;

use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogArticle;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogCmsService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditBlogArticle extends EditRecord
{
    protected static string $resource = BlogArticleResource::class;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected Width | string | null $maxContentWidth = Width::Screen;

    public static bool $formActionsAreSticky = true;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected ?string $subheading = 'Полноэкранный редактор статьи с черновиком, предпросмотром и историей изменений.';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var BlogArticle $record */
        $record = $this->getRecord();
        $data['tag_ids'] = $record->tags()->pluck('blog_tags.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();

        /** @var BlogArticle $record */
        return app(BlogCmsService::class)->updateArticle($record, $data, $systemAdmin);
    }

    public function autosave(): void
    {
        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();
        /** @var BlogArticle $record */
        $record = $this->getRecord();

        $data = $this->form->getState();
        app(BlogCmsService::class)->autosaveArticle($record, $data, $systemAdmin);
        $this->rememberData();

        Notification::make()->success()->title(trans_message('blog_cms.draft_autosaved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Предпросмотр')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.preview.view') ?? false)
                ->url(fn (): string => app(BlogCmsService::class)->makePreviewUrl($this->getRecord()), shouldOpenInNewTab: true),
            Action::make('publish')
                ->label('Опубликовать')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.publish') ?? false)
                ->action(function (): void {
                    /** @var SystemAdmin $systemAdmin */
                    $systemAdmin = Auth::guard('system_admin')->user();
                    app(BlogCmsService::class)->publishArticle($this->getRecord(), $systemAdmin);
                    Notification::make()->success()->title(trans_message('blog_cms.article_published'))->send();
                    $this->refreshFormData(['status', 'published_at']);
                }),
            Action::make('schedule')
                ->label('Запланировать')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->schema([
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label(trans_message('blog_cms.field_scheduled_at'))
                        ->required()
                        ->rules(['required', 'date', 'after:now']),
                ])
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.publish') ?? false)
                ->action(function (array $data): void {
                    /** @var SystemAdmin $systemAdmin */
                    $systemAdmin = Auth::guard('system_admin')->user();
                    app(BlogCmsService::class)->scheduleArticle($this->getRecord(), $systemAdmin, (string) $data['scheduled_at']);
                    Notification::make()->success()->title(trans_message('blog_cms.article_scheduled'))->send();
                    $this->refreshFormData(['status', 'scheduled_at', 'published_at']);
                }),
            Action::make('draft')
                ->label('В черновик')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(trans_message('blog_cms.draft_action_heading'))
                ->modalDescription(trans_message('blog_cms.draft_action_description'))
                ->modalSubmitActionLabel(trans_message('blog_cms.draft_action_confirm'))
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.publish') ?? false)
                ->action(function (): void {
                    /** @var SystemAdmin $systemAdmin */
                    $systemAdmin = Auth::guard('system_admin')->user();
                    app(BlogCmsService::class)->draftArticle($this->getRecord(), $systemAdmin);
                    Notification::make()->success()->title(trans_message('blog_cms.article_moved_to_draft'))->send();
                    $this->refreshFormData(['status', 'scheduled_at', 'published_at']);
                }),
            Action::make('archive')
                ->label('В архив')
                ->icon('heroicon-o-archive-box')
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.publish') ?? false)
                ->action(function (): void {
                    /** @var SystemAdmin $systemAdmin */
                    $systemAdmin = Auth::guard('system_admin')->user();
                    app(BlogCmsService::class)->archiveArticle($this->getRecord(), $systemAdmin);
                    Notification::make()->success()->title(trans_message('blog_cms.article_archived'))->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('duplicate')
                ->label('Дублировать')
                ->icon('heroicon-o-square-2-stack')
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.create') ?? false)
                ->action(function (): void {
                    /** @var SystemAdmin $systemAdmin */
                    $systemAdmin = Auth::guard('system_admin')->user();
                    $copy = app(BlogCmsService::class)->duplicateArticle($this->getRecord(), $systemAdmin);
                    $this->redirect(BlogArticleResource::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('autosave_now')
                ->label('Сохранить черновик')
                ->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.articles.update') ?? false)
                ->action(fn () => $this->autosave()),
        ];
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title(trans_message('blog_cms.draft_saved'));
    }
}
