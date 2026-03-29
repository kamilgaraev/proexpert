<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogMediaAssetResource\Pages;

use App\Filament\Resources\BlogMediaAssetResource;
use App\Models\Blog\BlogMediaAsset;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogMediaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateBlogMediaAsset extends CreateRecord
{
    protected static string $resource = BlogMediaAssetResource::class;

    protected function handleRecordCreation(array $data): BlogMediaAsset
    {
        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();
        $file = $data['upload_file'] ?? null;

        if (!$file instanceof TemporaryUploadedFile) {
            throw new \RuntimeException('Blog media file is required.');
        }

        return app(BlogMediaService::class)->uploadMarketingAsset($file, $systemAdmin, [
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'focal_point' => $data['focal_point'] ?? null,
        ]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Файл загружен');
    }
}
