<?php

namespace App\Http\Resources\File;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FileResource extends JsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'url' => $this->getFileUrl(),
            'uploaded_at' => $this->created_at->format('Y-m-d H:i:s'),
            'uploaded_by' => $this->user_id,
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