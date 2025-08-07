<?php

namespace App\Services\SiteRequest;

use App\Models\SiteRequest;
use App\DTOs\SiteRequest\SiteRequestDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\SiteRequestRepositoryInterface; // Будет создан
use App\Exceptions\BusinessLogicException;
use App\Services\FileService; // Добавили FileService
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; // Для работы с файлами
use App\Models\File; // Предполагается, что модель File существует
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Для логирования ошибок при удалении файлов
use Illuminate\Support\Facades\Config; // Добавляем импорт Config
use App\Events\PersonnelRequestCreated;
use App\Enums\SiteRequest\SiteRequestTypeEnum;

class SiteRequestService
{
    protected SiteRequestRepositoryInterface $siteRequestRepository;
    protected FileService $fileService; // Внедряем FileService

    public function __construct(
        SiteRequestRepositoryInterface $siteRequestRepository,
        FileService $fileService // Внедряем FileService
    ) {
        $this->siteRequestRepository = $siteRequestRepository;
        $this->fileService = $fileService; // Инициализируем FileService
    }

    public function getAll(array $filters, int $perPage, string $sortBy, string $sortDirection, array $relations = []): LengthAwarePaginator
    {
        return $this->siteRequestRepository->getAllPaginated($filters, $perPage, $sortBy, $sortDirection, $relations);
    }

    public function getById(int $id, int $organizationId, array $relations = []): SiteRequest
    {
        $siteRequest = $this->siteRequestRepository->findById($id, $organizationId, $relations);
        if (!$siteRequest) {
            throw new BusinessLogicException('Заявка не найдена.', 404);
        }
        return $siteRequest;
    }

    public function create(SiteRequestDTO $dto): SiteRequest
    {
        return DB::transaction(function () use ($dto) {
            $siteRequestData = $dto->toArrayForCreate();
            /** @var SiteRequest $siteRequest */
            $siteRequest = $this->siteRequestRepository->create($siteRequestData);

            if (!$siteRequest) {
                throw new BusinessLogicException('Не удалось создать заявку.', 500);
            }

            if (!empty($dto->files)) {
                $disk = Config::get('files.default_site_request_disk', 'public'); 
                $pathPrefix = 'site_request_files/' . $siteRequest->id;
                $thumbnailConfigs = Config::get('files.site_request_thumbnails', []);
                $this->processUploadedFiles($siteRequest, $dto->files, $disk, $pathPrefix, $thumbnailConfigs);
            }

            // Отправляем событие для заявок на персонал
            if ($siteRequest->request_type === SiteRequestTypeEnum::PERSONNEL_REQUEST) {
                event(new PersonnelRequestCreated($siteRequest->load('project', 'user')));
            }

            return $siteRequest->load('project', 'user', 'files');
        });
    }

    public function update(int $id, SiteRequestDTO $dto): SiteRequest
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->getById($id, $dto->organization_id, ['files']); // Загружаем файлы для возможного удаления

        return DB::transaction(function () use ($siteRequest, $dto, $id) {
            $siteRequestData = $dto->toArrayForUpdate();
            $this->siteRequestRepository->update($id, $siteRequestData);

            if (!empty($dto->files)) {
                // При обновлении, если переданы новые файлы, мы их просто добавляем.
                // Логика удаления старых файлов, если она нужна, должна быть реализована отдельно 
                // (например, если передается специальный флаг для замены файлов или список ID файлов для удаления).
                $disk = Config::get('files.default_site_request_disk', 'public');
                $pathPrefix = 'site_request_files/' . $siteRequest->id;
                $thumbnailConfigs = Config::get('files.site_request_thumbnails', []);
                $this->processUploadedFiles($siteRequest, $dto->files, $disk, $pathPrefix, $thumbnailConfigs);
            }
            
            return $siteRequest->refresh()->load('project', 'user', 'files');
        });
    }

    public function delete(int $id, int $organizationId): bool
    {
        $siteRequest = $this->getById($id, $organizationId, ['files']); // Загружаем связанные файлы

        return DB::transaction(function () use ($siteRequest, $id) {
            // Удаление связанных файлов
            foreach ($siteRequest->files as $file) {
                try {
                    // Передаем информацию о миниатюрах в FileService->delete
                    $this->fileService->delete($file->disk, $file->path, $file->additional_info['thumbnails'] ?? null);
                    $file->delete(); // Удаление записи из таблицы files
                } catch (\Exception $e) {
                    Log::error("Failed to delete file {$file->path} on disk {$file->disk} for site request {$id}: " . $e->getMessage());
                    // Решаем, должна ли ошибка при удалении файла прерывать весь процесс
                    // В данном случае, мы логируем ошибку и продолжаем, чтобы удалить саму заявку
                }
            }
            return $this->siteRequestRepository->delete($id);
        });
    }

    /**
     * @param SiteRequest $siteRequest
     * @param UploadedFile[] $uploadedFiles
     * @param string $disk
     * @param string $pathPrefix
     */
    protected function processUploadedFiles(SiteRequest $siteRequest, array $uploadedFiles, string $disk, string $pathPrefix, array $thumbnailConfigs): void
    {
        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile && $uploadedFile->isValid()) {
                try {
                    $fileData = $this->fileService->upload($uploadedFile, $disk, $pathPrefix, null, $thumbnailConfigs);

                    $siteRequest->files()->create([
                        'organization_id' => $siteRequest->organization_id,
                        'user_id' => Auth::id(), 
                        'name' => $fileData['name'],
                        'original_name' => $fileData['original_name'],
                        'path' => $fileData['path'], 
                        'mime_type' => $fileData['mime_type'],
                        'size' => $fileData['size'],
                        'disk' => $fileData['disk'],
                        'additional_info' => isset($fileData['thumbnails']) && !empty($fileData['thumbnails']) 
                                             ? ['thumbnails' => $fileData['thumbnails']] 
                                             : null,
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to upload file for site request {$siteRequest->id}: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
                    // Пропускаем этот файл и продолжаем с остальными, или выбрасываем исключение, если загрузка критична
                }
            }
        }
    }
    
    public function deleteFile(int $siteRequestId, int $fileId, int $organizationId): void
    {
        /** @var SiteRequest $siteRequest */
        $siteRequest = $this->getById($siteRequestId, $organizationId, ['files']);
        
        /** @var File|null $fileModel */
        $fileModel = $siteRequest->files()->find($fileId);

        if (!$fileModel) {
            throw new BusinessLogicException('Файл не найден у данной заявки.', 404);
        }

        if ($fileModel->organization_id !== $organizationId) {
             throw new BusinessLogicException('Доступ к файлу запрещен по причине несоответствия организации.', 403);
        }

        try {
            // Передаем информацию о миниатюрах в FileService->delete
            $this->fileService->delete($fileModel->disk, $fileModel->path, $fileModel->additional_info['thumbnails'] ?? null);
            $fileModel->delete(); // Удаление записи из таблицы files
        } catch (\Exception $e) {
            Log::error("Failed to delete file {$fileModel->path} on disk {$fileModel->disk} for site request {$siteRequestId}, file ID {$fileId}: " . $e->getMessage());
            throw new BusinessLogicException('Не удалось удалить файл.', 500); // Перебрасываем как бизнес-исключение
        }
    }

    /**
     * Получить отфильтрованные заявки для админской панели
     */
    public function getFilteredSiteRequests(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->siteRequestRepository->getAllPaginated(
            $filters,
            $perPage,
            'created_at',
            'desc',
            ['project', 'user']
        );
    }

    /**
     * Получить статистику заявок для дашборда
     */
    public function getSiteRequestStats(int $organizationId): array
    {
        $query = SiteRequest::where('organization_id', $organizationId);
        
        $totalRequests = $query->count();
        $pendingRequests = $query->where('status', 'pending')->count();
        $inProgressRequests = $query->where('status', 'in_progress')->count();
        $completedRequests = $query->where('status', 'completed')->count();
        $overdueRequests = $query->where('required_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
        
        $personnelRequests = $query->where('request_type', 'personnel_request')->count();
        $materialRequests = $query->where('request_type', 'material_request')->count();
        $equipmentRequests = $query->where('request_type', 'equipment_request')->count();
        
        $urgentRequests = $query->where('priority', 'urgent')->count();
        $highPriorityRequests = $query->where('priority', 'high')->count();
        
        // Статистика по заявкам на персонал
        $personnelStats = $this->getPersonnelRequestStats($organizationId);
        
        return [
            'total_requests' => $totalRequests,
            'by_status' => [
                'pending' => $pendingRequests,
                'in_progress' => $inProgressRequests,
                'completed' => $completedRequests,
                'overdue' => $overdueRequests,
            ],
            'by_type' => [
                'personnel_request' => $personnelRequests,
                'material_request' => $materialRequests,
                'equipment_request' => $equipmentRequests,
            ],
            'by_priority' => [
                'urgent' => $urgentRequests,
                'high' => $highPriorityRequests,
            ],
            'personnel_stats' => $personnelStats,
        ];
    }

    /**
     * Получить статистику заявок на персонал
     */
    private function getPersonnelRequestStats(int $organizationId): array
    {
        $personnelRequests = SiteRequest::where('organization_id', $organizationId)
            ->where('request_type', 'personnel_request')
            ->get();
        
        $totalPersonnelNeeded = $personnelRequests->sum('personnel_count');
        $averageHourlyRate = $personnelRequests->where('hourly_rate', '>', 0)->avg('hourly_rate');
        
        $byPersonnelType = $personnelRequests->groupBy('personnel_type')
            ->map(function ($requests, $type) {
                return [
                    'count' => $requests->count(),
                    'total_personnel' => $requests->sum('personnel_count'),
                    'average_rate' => $requests->where('hourly_rate', '>', 0)->avg('hourly_rate') ?? 0,
                ];
            })->toArray();
        
        return [
            'total_requests' => $personnelRequests->count(),
            'total_personnel_needed' => $totalPersonnelNeeded,
            'average_hourly_rate' => round($averageHourlyRate ?? 0, 2),
            'by_personnel_type' => $byPersonnelType,
        ];
    }

    /**
     * Создать заявку (алиас для create для совместимости с админским контроллером)
     */
    public function createSiteRequest(SiteRequestDTO $dto): SiteRequest
    {
        return $this->create($dto);
    }

    /**
     * Обновить заявку (алиас для update для совместимости с админским контроллером)
     */
    public function updateSiteRequest(SiteRequest $siteRequest, SiteRequestDTO $dto): SiteRequest
    {
        return $this->update($siteRequest->id, $dto);
    }

    /**
     * Удалить заявку (алиас для delete для совместимости с админским контроллером)
     */
    public function deleteSiteRequest(SiteRequest $siteRequest): bool
    {
        return $this->delete($siteRequest->id, $siteRequest->organization_id);
    }
}