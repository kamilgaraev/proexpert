<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogMediaAssetResource\Pages;

use App\Filament\Resources\BlogMediaAssetResource;
use App\Models\Blog\BlogMediaAsset;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogMediaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditBlogMediaAsset extends EditRecord
{
    protected static string $resource = BlogMediaAssetResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['upload_file'] = null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();
        /** @var BlogMediaAsset $record */
        $file = $data['upload_file'] ?? null;

        if ($file instanceof TemporaryUploadedFile) {
            $newAsset = app(BlogMediaService::class)->uploadMarketingAsset($file, $systemAdmin, [
                'alt_text' => $data['alt_text'] ?? null,
                'caption' => $data['caption'] ?? null,
                'focal_point' => $data['focal_point'] ?? null,
            ]);

            app(BlogMediaService::class)->deleteAsset($record);

            return $newAsset;
        }

        $record->update([
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'focal_point' => $data['focal_point'] ?? null,
        ]);

        app(BlogMediaService::class)->refreshUsageMetadata($record);

        return $record->fresh();
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Медиа обновлена');
    }
}
