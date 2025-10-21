<?php

namespace App\Services\Log;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Models\User;
use App\Models\Project;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\Models\Log\WorkCompletionLog;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log as LaravelLog;
use App\Services\Material\MaterialService;

class LogService
{
    protected MaterialUsageLogRepositoryInterface $materialUsageLogRepository;
    protected WorkCompletionLogRepositoryInterface $workCompletionLogRepository;
    protected ProjectRepositoryInterface $projectRepository;
    protected MaterialRepositoryInterface $materialRepository;
    protected WorkTypeRepositoryInterface $workTypeRepository;
    protected ImageUploadService $imageUploadService;
    protected MaterialService $materialService;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialUsageLogRepository,
        WorkCompletionLogRepositoryInterface $workCompletionLogRepository,
        ProjectRepositoryInterface $projectRepository,
        MaterialRepositoryInterface $materialRepository,
        WorkTypeRepositoryInterface $workTypeRepository,
        ImageUploadService $imageUploadService,
        MaterialService $materialService
    ) {
        $this->materialUsageLogRepository = $materialUsageLogRepository;
        $this->workCompletionLogRepository = $workCompletionLogRepository;
        $this->projectRepository = $projectRepository;
        $this->materialRepository = $materialRepository;
        $this->workTypeRepository = $workTypeRepository;
        $this->imageUploadService = $imageUploadService;
        $this->materialService = $materialService;
    }

    /**
     * Залогировать выполнение работы прорабом.
     *
     * @param array $data Данные лога (project_id, work_type_id, quantity, completion_date, notes)
     * @param User $user Прораб, выполняющий действие
     * @return \App\Models\Models\Log\WorkCompletionLog
     * @throws BusinessLogicException
     */
    public function logWorkCompletion(array $data, User $user, ?UploadedFile $photoFile): WorkCompletionLog
    {
        $projectId = Arr::get($data, 'project_id');
        $workTypeId = Arr::get($data, 'work_type_id');

        $project = $this->checkUserProjectAccess($user, $projectId);
        $workType = $this->workTypeRepository->find($workTypeId);
        if (!$workType || $workType->organization_id !== $project->organization_id) {
            throw new BusinessLogicException('Вид работы не найден в организации проекта.', 404);
        }

        $photoPath = null;
        if ($photoFile) {
            $photoPath = $this->imageUploadService->upload($photoFile, 'work_completion_photos', null, 'public');
            if (!$photoPath) {
                LaravelLog::warning('[LogService] Failed to upload work completion photo.', ['user_id' => $user->id, 'project_id' => $projectId]);
            }
        }

        $logData = [
            'project_id' => $projectId,
            'work_type_id' => $workTypeId,
            'user_id' => $user->id,
            'organization_id' => $project->organization_id,
            'quantity' => Arr::get($data, 'quantity'),
            'completion_date' => Carbon::parse(Arr::get($data, 'completion_date'))->toDateString(),
            'performers_description' => Arr::get($data, 'performers_description'),
            'photo_path' => $photoPath,
            'notes' => Arr::get($data, 'notes'),
            'unit_price' => Arr::get($data, 'unit_price'),
            'total_price' => Arr::get($data, 'total_price'),
        ];
        return $this->workCompletionLogRepository->create($logData);
    }

    /**
     * Проверяет, существует ли проект и назначен ли на него указанный пользователь.
     *
     * @param User $user
     * @param int $projectId
     * @return Project
     * @throws BusinessLogicException
     */
    protected function checkUserProjectAccess(User $user, int $projectId): Project
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден.', 404);
        }
        if (!$project->users()->where('user_id', $user->id)->exists()) {
            throw new BusinessLogicException('Пользователь не назначен на данный проект.', 403);
        }
        return $project;
    }
} 