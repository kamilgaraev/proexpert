<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Pages;

use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogArticle;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogCmsService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;

class CreateBlogArticle extends CreateRecord
{
    protected static string $resource = BlogArticleResource::class;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected Width | string | null $maxContentWidth = Width::Screen;

    public static bool $formActionsAreSticky = true;

    protected ?string $subheading = 'Создайте базовый draft и сразу переходите в полноценный редактор.';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function handleRecordCreation(array $data): BlogArticle
    {
        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();

        return app(BlogCmsService::class)->createDraft($data, $systemAdmin);
    }

    protected function getRedirectUrl(): string
    {
        return BlogArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title(trans_message('blog_cms.draft_created'));
    }
}
