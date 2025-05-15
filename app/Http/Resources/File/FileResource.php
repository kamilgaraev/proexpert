<?php

namespace App\Http\Resources\File;

use Illuminate\Http\Resources\Json\JsonResource;
// Storage не используется напрямую, если модель сама генерирует URL

class FileResource extends JsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        $thumbnails = [];
        // Доступ к "raw" данным миниатюр через аксессор getThumbnailsAttribute (->thumbnails)
        foreach ($this->thumbnails as $suffix => $thumbData) {
            if (isset($thumbData['url'])) {
                $thumbnails[$suffix] = $thumbData['url'];
            } elseif (isset($thumbData['path']) && isset($thumbData['disk'])) {
                // Если URL не был сохранен напрямую, генерируем его
                $thumbnails[$suffix] = $this->getThumbnailUrl($suffix);
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name, // Имя файла на диске (сгенерированное или кастомное)
            'original_name' => $this->original_name, // Оригинальное имя файла от клиента
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'disk' => $this->disk,
            'url' => $this->url, // Аксессор из модели File
            'thumbnails' => $thumbnails, // Массив URL-ов миниатюр ['suffix' => 'url']
            // Показываем additional_info только админам для отладки или если есть специальный запрос
            'additional_info' => $this->when(
                $this->additional_info && $request->user() && method_exists($request->user(), 'isAdmin') && $request->user()->isAdmin(), 
                $this->additional_info
            ), 
            'uploaded_at' => $this->created_at->format('Y-m-d H:i:s'),
            'user_id' => $this->user_id, // Оставляем для информации, кто загрузил
            // Можно добавить ресурс пользователя, если нужно больше деталей о нем
            // 'uploaded_by' => new UserMiniResource($this->whenLoaded('user')),
        ];
    }

    /**
     * Получить URL файла.
     *
     * @return string
     */
    protected function getFileUrl()
    {
        if ($this->storage_disk === 's3') {
            return Storage::disk('s3')->url($this->filepath);
        }

        return asset('storage/' . $this->filepath);
    }
} 