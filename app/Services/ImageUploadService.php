<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImageUploadService
{
    protected string $disk = 's3'; // По умолчанию используем S3

    /**
     * Загружает файл в указанную директорию на диск.
     *
     * @param UploadedFile $file Файл для загрузки.
     * @param string $directory Директория в S3 для сохранения (например, 'avatars', 'logos').
     * @param string|null $existingPath Путь к существующему файлу для удаления (если это обновление).
     * @param string $visibility Видимость файла ('public' или 'private').
     * @return string|false Путь к загруженному файлу или false в случае ошибки.
     */
    public function upload(UploadedFile $file, string $directory, ?string $existingPath = null, string $visibility = 'public'): string|false
    {
        Log::info('[ImageUploadService] Starting upload process.', [
            'directory' => $directory,
            'original_filename' => $file->getClientOriginalName(),
            'visibility' => $visibility,
            'disk' => $this->disk,
            'existing_path' => $existingPath
        ]);

        // Удаляем существующий файл, если он есть
        if ($existingPath) {
            Log::info('[ImageUploadService] Attempting to delete existing file.', ['path' => $existingPath]);
            $deleted = $this->delete($existingPath);
            Log::info('[ImageUploadService] Deletion result for existing file.', ['path' => $existingPath, 'success' => $deleted]);
        }

        // Генерируем уникальное имя файла, сохраняя расширение
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $fullPath = $directory . '/' . $filename;

        try {
            Log::info('[ImageUploadService] Attempting to store file.', [
                'disk' => $this->disk,
                'path' => $fullPath,
                'visibility' => $visibility
            ]);
            
            // storeAs возвращает путь или false при ошибке (хотя чаще кидает Exception)
            $storedPath = $file->storeAs($directory, $filename, [
                'disk' => $this->disk,
                'visibility' => $visibility,
            ]);

            if ($storedPath === false) {
                Log::error('[ImageUploadService] storeAs returned false.', [
                    'disk' => $this->disk,
                    'path' => $fullPath
                ]);
                return false;
            }

            Log::info('[ImageUploadService] File stored successfully.', ['path' => $storedPath]);
            return $storedPath;

        } catch (\Throwable $e) {
            // Логируем любую ошибку, возникшую при сохранении на диск
            Log::error('[ImageUploadService] Exception during file storage.', [
                'disk' => $this->disk,
                'path' => $fullPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false; // Возвращаем false, чтобы контроллер мог обработать ошибку
        }
    }

    /**
     * Удаляет файл с диска.
     *
     * @param string|null $path Путь к файлу для удаления.
     * @return bool True, если удаление успешно или путь не указан, false при ошибке.
     */
    public function delete(?string $path): bool
    {
        if (!$path) {
            return true; // Нечего удалять
        }

        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }

        return true; // Файла и так нет
    }

    /**
     * Получает публичный URL для файла.
     *
     * @param string|null $path Путь к файлу.
     * @return string|null URL или null, если путь не указан.
     */
    public function getUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Получает временный (подписанный) URL для приватного файла.
     *
     * @param string|null $path Путь к файлу.
     * @param int $minutes Время жизни URL в минутах.
     * @return string|null URL или null, если путь не указан.
     */
    public function getTemporaryUrl(?string $path, int $minutes = 5): ?string
    {
        if (!$path) {
            return null;
        }
        return Storage::disk($this->disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }
} 