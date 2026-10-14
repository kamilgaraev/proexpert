<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class File extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (File $file): void {
            if ($file->isWorkforcePrivateDocument()) {
                throw new \DomainException(trans_message('workforce.errors.hr_document_disposal_forbidden'));
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'fileable_id',
        'fileable_type',
        'user_id',
        'name',
        'original_name',
        'path',
        'mime_type',
        'size',
        'disk',
        'type',
        'category',
        'additional_info',
    ];

    protected $casts = [
        'size' => 'integer',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит файл.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить пользователя, загрузившего файл.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить связанную модель.
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Получить полный URL к файлу.
     */
    public function getUrlAttribute(): string
    {
        if ($this->isWorkforcePrivateDocument()) {
            return '';
        }
        /** @var \Illuminate\Contracts\Filesystem\Filesystem|\Illuminate\Filesystem\FilesystemAdapter $storageAdapter */
        $storageAdapter = Storage::disk($this->disk);

        return $storageAdapter->url($this->path);
    }

    public function isWorkforcePrivateDocument(): bool
    {
        return str_starts_with((string) $this->category, 'workforce_hr')
            || str_starts_with((string) $this->category, 'workforce_timekeeping')
            || in_array($this->fileable_type, [
                \App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee::class,
                \App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod::class,
            ], true);
    }

    /**
     * Получить всю информацию о миниатюрах из поля additional_info.
     */
    public function getThumbnailsAttribute(): array
    {
        return $this->additional_info['thumbnails'] ?? [];
    }

    /**
     * Получить информацию о конкретной миниатюре по ее суффиксу.
     *
     * @param  string  $suffix  Суффикс имени миниатюры (ключ в массиве thumbnails, например, '_thumb', '_medium').
     * @return array|null Возвращает массив с данными миниатюры [path, url, disk, name, width, height] или null, если не найдено.
     */
    public function getThumbnail(string $suffix): ?array
    {
        return $this->thumbnails[$suffix] ?? null;
    }

    /**
     * Получить URL конкретной миниатюры по ее суффиксу.
     *
     * @param  string  $suffix  Суффикс имени миниатюры.
     * @return string|null URL миниатюры или null, если не найдено.
     */
    public function getThumbnailUrl(string $suffix): ?string
    {
        if ($this->isWorkforcePrivateDocument()) {
            return null;
        }
        $thumbnailData = $this->getThumbnail($suffix);
        if (isset($thumbnailData['url'])) {
            return $thumbnailData['url'];
        }
        if (isset($thumbnailData['path']) && isset($thumbnailData['disk'])) {
            /** @var \Illuminate\Contracts\Filesystem\Filesystem|\Illuminate\Filesystem\FilesystemAdapter $storageAdapter */
            $storageAdapter = Storage::disk($thumbnailData['disk']);

            return $storageAdapter->url($thumbnailData['path']);
        }

        return null;
    }
}
