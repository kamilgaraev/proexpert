<?php

namespace App\Services\Log;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Models\User;
use App\Models\Project;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class LogService
{
    protected MaterialUsageLogRepositoryInterface $materialUsageLogRepository;
    protected WorkCompletionLogRepositoryInterface $workCompletionLogRepository;
    protected ProjectRepositoryInterface $projectRepository;
    protected MaterialRepositoryInterface $materialRepository;
    protected WorkTypeRepositoryInterface $workTypeRepository;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialUsageLogRepository,
        WorkCompletionLogRepositoryInterface $workCompletionLogRepository,
        ProjectRepositoryInterface $projectRepository,
        MaterialRepositoryInterface $materialRepository,
        WorkTypeRepositoryInterface $workTypeRepository
    ) {
        $this->materialUsageLogRepository = $materialUsageLogRepository;
        $this->workCompletionLogRepository = $workCompletionLogRepository;
        $this->projectRepository = $projectRepository;
        $this->materialRepository = $materialRepository;
        $this->workTypeRepository = $workTypeRepository;
    }

    /**
     * Залогировать использование материала прорабом.
     *
     * @param array $data Данные лога (project_id, material_id, quantity, usage_date, notes)
     * @param User $user Прораб, выполняющий действие
     * @return \App\Models\Models\Log\MaterialUsageLog
     * @throws BusinessLogicException
     */
    public function logMaterialUsage(array $data, User $user): \App\Models\Models\Log\MaterialUsageLog
    {
        $projectId = Arr::get($data, 'project_id');
        $materialId = Arr::get($data, 'material_id');

        // 1. Проверка, что проект существует и пользователь на него назначен
        $project = $this->checkUserProjectAccess($user, $projectId);

        // 2. Проверка, что материал существует и принадлежит организации проекта
        $material = $this->materialRepository->find($materialId);
        if (!$material || $material->organization_id !== $project->organization_id) {
            throw new BusinessLogicException('Материал не найден в организации проекта.', 404);
        }

        // 3. Подготовка данных для сохранения
        $logData = [            'project_id' => $projectId,            'material_id' => $materialId,            'user_id' => $user->id,            'quantity' => Arr::get($data, 'quantity'),            'usage_date' => Carbon::parse(Arr::get($data, 'usage_date'))->toDateString(), // Приводим к дате            'notes' => Arr::get($data, 'notes'),
        ];

        // 4. Сохранение лога
        return $this->materialUsageLogRepository->create($logData);
    }

    /**
     * Залогировать выполнение работы прорабом.
     *
     * @param array $data Данные лога (project_id, work_type_id, quantity, completion_date, notes)
     * @param User $user Прораб, выполняющий действие
     * @return \App\Models\Models\Log\WorkCompletionLog
     * @throws BusinessLogicException
     */
    public function logWorkCompletion(array $data, User $user): \App\Models\Models\Log\WorkCompletionLog
    {
        $projectId = Arr::get($data, 'project_id');
        $workTypeId = Arr::get($data, 'work_type_id');

        // 1. Проверка, что проект существует и пользователь на него назначен
        $project = $this->checkUserProjectAccess($user, $projectId);

        // 2. Проверка, что вид работы существует и принадлежит организации проекта
        $workType = $this->workTypeRepository->find($workTypeId);
        if (!$workType || $workType->organization_id !== $project->organization_id) {
            throw new BusinessLogicException('Вид работы не найден в организации проекта.', 404);
        }

        // 3. Подготовка данных
        $logData = [            'project_id' => $projectId,            'work_type_id' => $workTypeId,            'user_id' => $user->id,            'quantity' => Arr::get($data, 'quantity'), // quantity может быть null
            'completion_date' => Carbon::parse(Arr::get($data, 'completion_date'))->toDateString(),            'notes' => Arr::get($data, 'notes'),
        ];

        // 4. Сохранение лога
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
        /** @var Project|null $project */
        $project = $this->projectRepository->find($projectId);

        if (!$project) {
            throw new BusinessLogicException('Проект не найден.', 404);
        }

        // Проверяем, что пользователь привязан к проекту через pivot таблицу
        if (!$project->users()->where('user_id', $user->id)->exists()) {
            // Дополнительно можно проверить, состоит ли пользователь в организации проекта,
            // но для прораба достаточно проверки назначения на проект.
            throw new BusinessLogicException('У вас нет доступа к этому проекту.', 403);
        }

        return $project;
    }
} 