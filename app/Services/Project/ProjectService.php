<?php declare(strict_types=1);

namespace App\Services\Project;

use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\User;
use App\Models\Role;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;
use App\Services\Project\ProjectContextService;
use App\Enums\ProjectOrganizationRole;
use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Events\ProjectOrganizationRemoved;
use App\Events\ProjectCreated;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\DTOs\Project\ProjectDTO;

class ProjectService
{
    protected ProjectRepositoryInterface $projectRepository;
    protected UserRepositoryInterface $userRepository;
    protected MaterialRepositoryInterface $materialRepository;
    protected WorkTypeRepositoryInterface $workTypeRepository;
    protected LoggingService $logging;
    protected OrganizationProfileService $organizationProfileService;
    protected ProjectContextService $projectContextService;
    protected OrganizationScopeInterface $orgScope;
    protected ProjectParticipantService $projectParticipantService;

    public function __construct(
        ProjectRepositoryInterface $projectRepository,
        UserRepositoryInterface $userRepository,
        MaterialRepositoryInterface $materialRepository,
        WorkTypeRepositoryInterface $workTypeRepository,
        LoggingService $logging,
        OrganizationProfileService $organizationProfileService,
        ProjectContextService $projectContextService,
        OrganizationScopeInterface $orgScope,
        ProjectParticipantService $projectParticipantService
    ) {
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
        $this->materialRepository = $materialRepository;
        $this->workTypeRepository = $workTypeRepository;
        $this->logging = $logging;
        $this->organizationProfileService = $organizationProfileService;
        $this->projectContextService = $projectContextService;
        $this->orgScope = $orgScope;
        $this->projectParticipantService = $projectParticipantService;
    }

    private function resolveProjectRoleFromValues(?string $roleNew, ?string $roleLegacy): ?ProjectOrganizationRole
    {
        $roleValue = $roleNew ?: $roleLegacy;

        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    private function invalidateProjectParticipantContexts(int $projectId): void
    {
        $organizationIds = DB::table('project_organization')
            ->useWritePdo()
            ->where('project_id', $projectId)
            ->pluck('organization_id')
            ->push(Project::query()->useWritePdo()->whereKey($projectId)->value('organization_id'))
            ->filter()
            ->unique()
            ->values();

        foreach ($organizationIds as $organizationId) {
            $this->projectContextService->invalidateContext($projectId, (int) $organizationId);
        }
    }

    /**
     * Helper РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ ID РѕСЂРіР°РЅРёР·Р°С†РёРё РёР· Р·Р°РїСЂРѕСЃР°.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user(); // РџРѕР»СѓС‡Р°РµРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РёР· Р·Р°РїСЂРѕСЃР°
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        
        if (!$organizationId) {
            Log::error('Failed to determine organization context', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ.', 500);
        }
        return (int)$organizationId;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїР°РіРёРЅРёСЂРѕРІР°РЅРЅС‹Р№ СЃРїРёСЃРѕРє РїСЂРѕРµРєС‚РѕРІ РґР»СЏ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     * РџРѕРґРґРµСЂР¶РёРІР°РµС‚ С„РёР»СЊС‚СЂР°С†РёСЋ Рё СЃРѕСЂС‚РёСЂРѕРІРєСѓ.
     */
    public function getProjectsForCurrentOrg(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        // РЎРѕР±РёСЂР°РµРј С„РёР»СЊС‚СЂС‹ РёР· Р·Р°РїСЂРѕСЃР°
        $filters = [
            'name' => $request->query('name'),
            'status' => $request->query('status'),
            'is_archived' => $request->query('is_archived'), // РџСЂРёРЅРёРјР°РµРј 'true', 'false', '1', '0' РёР»Рё null
        ];
        // РћР±СЂР°Р±Р°С‚С‹РІР°РµРј is_archived, С‡С‚РѕР±С‹ РјРѕР¶РЅРѕ Р±С‹Р»Рѕ РїРµСЂРµРґР°РІР°С‚СЊ Р±СѓР»РµРІС‹ Р·РЅР°С‡РµРЅРёСЏ
        if (isset($filters['is_archived'])) {
            $filters['is_archived'] = filter_var($filters['is_archived'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_archived']); // РЈРґР°Р»СЏРµРј, РµСЃР»Рё РЅРµ РїРµСЂРµРґР°РЅ
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        // РџР°СЂР°РјРµС‚СЂС‹ СЃРѕСЂС‚РёСЂРѕРІРєРё
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        // TODO: Р”РѕР±Р°РІРёС‚СЊ РІР°Р»РёРґР°С†РёСЋ sortBy, С‡С‚РѕР±С‹ СЂР°Р·СЂРµС€РёС‚СЊ С‚РѕР»СЊРєРѕ РѕРїСЂРµРґРµР»РµРЅРЅС‹Рµ РїРѕР»СЏ
        $allowedSortBy = ['name', 'status', 'start_date', 'end_date', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'created_at'; // РџРѕ СѓРјРѕР»С‡Р°РЅРёСЋ, РµСЃР»Рё РїРµСЂРµРґР°РЅРѕ РЅРµРІР°Р»РёРґРЅРѕРµ РїРѕР»Рµ
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        return $this->projectRepository->getProjectsForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ РїСЂРѕРµРєС‚.
     *
     * @param ProjectDTO $projectDTO
     * @param Request $request // Р”Р»СЏ РїРѕР»СѓС‡РµРЅРёСЏ organization_id
     * @return Project
     * @throws BusinessLogicException
     */
    public function createProject(ProjectDTO $projectDTO, Request $request): Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $user = $request->user();
        
        // BUSINESS: РќР°С‡Р°Р»Рѕ СЃРѕР·РґР°РЅРёСЏ РїСЂРѕРµРєС‚Р° - РєР»СЋС‡РµРІР°СЏ Р±РёР·РЅРµСЃ-РјРµС‚СЂРёРєР°
        $this->logging->business('project.creation.started', [
            'project_name' => $projectDTO->name,
            'project_description' => $projectDTO->description ?? null,
            'organization_id' => $organizationId,
            'created_by_user_id' => $user?->id,
            'created_by_email' => $user?->email,
            'project_address' => $projectDTO->address ?? null
        ]);
        
        $dataToCreate = $this->withGeocodingState($projectDTO->toArray());
        $dataToCreate['organization_id'] = $organizationId;
        $dataToCreate['is_head'] = true;
        
        $project = $this->projectRepository->create($dataToCreate);
        
        event(new ProjectCreated($project));
        
        // AUDIT: РЎРѕР·РґР°РЅРёРµ РїСЂРѕРµРєС‚Р° - РІР°Р¶РЅРѕ РґР»СЏ compliance Рё РѕС‚СЃР»РµР¶РёРІР°РЅРёСЏ РёР·РјРµРЅРµРЅРёР№
        $this->logging->audit('project.created', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'project_description' => $project->description,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'created_by_email' => $user?->email,
            'is_head_project' => true,
            'creation_date' => $project->created_at?->toISOString()
        ]);
        
        // BUSINESS: РЈСЃРїРµС€РЅРѕРµ СЃРѕР·РґР°РЅРёРµ РїСЂРѕРµРєС‚Р° - РєР»СЋС‡РµРІР°СЏ РјРµС‚СЂРёРєР° СЂРѕСЃС‚Р°
        $this->logging->business('project.created', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'timestamp' => now()->toISOString()
        ]);
        
        return $project;
    }

    public function findProjectByIdForCurrentOrg(int $id, Request $request): ?Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $project = Project::query()
            ->useWritePdo()
            ->find($id);

        if (!$project) {
            return null;
        }

        $belongsToOrg = $project->organization_id === $organizationId
            || ProjectOrganization::query()
                ->useWritePdo()
                ->where('project_id', $project->id)
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->exists();

        return $belongsToOrg ? $project : null;
    }

    /**
     * РћР±РЅРѕРІРёС‚СЊ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ РїСЂРѕРµРєС‚.
     *
     * @param int $id ID РїСЂРѕРµРєС‚Р°
     * @param ProjectDTO $projectDTO
     * @param Request $request // Р”Р»СЏ РїСЂРѕРІРµСЂРєРё РѕСЂРіР°РЅРёР·Р°С†РёРё
     * @return Project|null
     * @throws BusinessLogicException
     */
    public function updateProject(int $id, ProjectDTO $projectDTO, Request $request): ?Project
    {
        $project = $this->findProjectByIdForCurrentOrg($id, $request);
        if (!$project) {
            throw new BusinessLogicException('Project not found in your organization or you do not have permission.', 404);
        }

        $updated = $this->projectRepository->update($id, $this->withGeocodingState($projectDTO->toArray()));
        return $updated ? $this->projectRepository->find($id) : null;
    }

    private function withGeocodingState(array $data): array
    {
        if (($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null) {
            $data['geocoded_at'] = now();
            $data['geocoding_status'] = 'geocoded';
        }

        return $data;
    }

    public function deleteProject(int $id, Request $request): bool
    {
        $project = $this->findProjectByIdForCurrentOrg($id, $request);
        if (!$project) {
            throw new BusinessLogicException('Project not found in your organization', 404);
        }
        
        $user = $request->user();
        $organizationId = $this->getCurrentOrgId($request);
        
        // SECURITY: РџРѕРїС‹С‚РєР° СѓРґР°Р»РµРЅРёСЏ РїСЂРѕРµРєС‚Р° - РІР°Р¶РЅРѕРµ security СЃРѕР±С‹С‚РёРµ
        $this->logging->security('project.deletion.attempt', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'organization_id' => $organizationId,
            'requested_by' => $user?->id,
            'requested_by_email' => $user?->email
        ]);
        
        // РЎРѕС…СЂР°РЅСЏРµРј РґР°РЅРЅС‹Рµ РїСЂРѕРµРєС‚Р° РґР»СЏ Р»РѕРіРёСЂРѕРІР°РЅРёСЏ РґРѕ СѓРґР°Р»РµРЅРёСЏ
        $projectData = [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'project_description' => $project->description,
            'project_address' => $project->address,
            'organization_id' => $organizationId,
            'was_head_project' => $project->is_head,
            'created_at' => $project->created_at?->toISOString()
        ];
        
        $result = $this->projectRepository->delete($id);
        
        if ($result) {
            // AUDIT: РЈСЃРїРµС€РЅРѕРµ СѓРґР°Р»РµРЅРёРµ РїСЂРѕРµРєС‚Р° - РєСЂРёС‚РёС‡РµСЃРєРё РІР°Р¶РЅРѕ РґР»СЏ compliance
            $this->logging->audit('project.deleted', array_merge($projectData, [
                'deleted_by' => $user?->id,
                'deleted_by_email' => $user?->email,
                'deleted_at' => now()->toISOString()
            ]));
            
            // BUSINESS: РЈРґР°Р»РµРЅРёРµ РїСЂРѕРµРєС‚Р° - РІР°Р¶РЅР°СЏ Р±РёР·РЅРµСЃ-РјРµС‚СЂРёРєР° (РјРѕР¶РµС‚ СѓРєР°Р·С‹РІР°С‚СЊ РЅР° РїСЂРѕР±Р»РµРјС‹)
            $this->logging->business('project.deleted', [
                'project_id' => $projectData['project_id'],
                'project_name' => $projectData['project_name'],
                'organization_id' => $organizationId,
                'deleted_by' => $user?->id,
                'project_lifetime_days' => $project->created_at ? $project->created_at->diffInDays(now()) : null
            ]);
        } else {
            // TECHNICAL: РќРµСѓРґР°С‡РЅРѕРµ СѓРґР°Р»РµРЅРёРµ РїСЂРѕРµРєС‚Р°
            $this->logging->technical('project.deletion.failed', [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'organization_id' => $organizationId,
                'attempted_by' => $user?->id,
                'error' => 'Repository delete returned false'
            ], 'error');
        }
        
        return $result;
    }

    public function assignForemanToProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ РІ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.', 404);
        }

        $user = $this->userRepository->find($userId);
        
        // РџРѕР»СѓС‡Р°РµРј ID РєРѕРЅС‚РµРєСЃС‚Р° Р°РІС‚РѕСЂРёР·Р°С†РёРё РґР»СЏ РѕСЂРіР°РЅРёР·Р°С†РёРё
        $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        $contextId = $authContext ? $authContext->id : null;
        
        if (!$user 
            || !$user->is_active 
            || !app(\App\Domain\Authorization\Services\AuthorizationService::class)->hasRole($user, 'foreman', $contextId) 
            || !$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()
           ) { 
            throw new BusinessLogicException('РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РЅР°Р№РґРµРЅ, РЅРµР°РєС‚РёРІРµРЅ РёР»Рё РЅРµ СЏРІР»СЏРµС‚СЃСЏ РїСЂРѕСЂР°Р±РѕРј РІ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.', 404);
        }

        try {
            // Р”РѕР±Р°РІР»СЏРµРј СЂРѕР»СЊ foreman РІ pivot. Р•СЃР»Рё Р·Р°РїРёСЃСЊ СѓР¶Рµ РµСЃС‚СЊ вЂ” РѕР±РЅРѕРІР»СЏРµРј.
            $project->users()->syncWithoutDetaching([$userId => ['role' => 'foreman']]);
            Log::info('Foreman assigned to project', ['project_id' => $projectId, 'user_id' => $userId, 'admin_id' => $request->user()->id]);
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23505) {
                Log::warning('Attempted to assign already assigned foreman to project', ['project_id' => $projectId, 'user_id' => $userId]);
                return true; 
            }
            Log::error('Database error assigning foreman to project', ['project_id' => $projectId, 'user_id' => $userId, 'exception' => $e]);
            throw new BusinessLogicException('РћС€РёР±РєР° Р±Р°Р·С‹ РґР°РЅРЅС‹С… РїСЂРё РЅР°Р·РЅР°С‡РµРЅРёРё РїСЂРѕСЂР°Р±Р°.', 500, $e);
        }
    }

    public function detachForemanFromProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ РІ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.', 404);
        }

        $detachedCount = $project->users()->detach($userId);

        if ($detachedCount > 0) {
             Log::info('Foreman detached from project', ['project_id' => $projectId, 'user_id' => $userId, 'admin_id' => $request->user()->id]);
            return true;
        } else {
            Log::warning('Attempted to detach foreman not assigned to project', ['project_id' => $projectId, 'user_id' => $userId]);
            return false;
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІСЃРµ РїСЂРѕРµРєС‚С‹ РґР»СЏ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё (Р±РµР· РїР°РіРёРЅР°С†РёРё).
     * @deprecated РСЃРїРѕР»СЊР·СѓР№С‚Рµ getProjectsForCurrentOrg СЃ РїР°РіРёРЅР°С†РёРµР№.
     */
    public function getAllProjectsForCurrentOrg(Request $request): Collection 
    { 
        $organizationId = $this->getCurrentOrgId($request); 
        // РњРµС‚РѕРґ getProjectsForOrganization РґРѕР»Р¶РµРЅ РІРѕР·РІСЂР°С‰Р°С‚СЊ РїР°РіРёРЅР°С‚РѕСЂ, 
        // РµСЃР»Рё РЅСѓР¶РЅР° РєРѕР»Р»РµРєС†РёСЏ, РЅСѓР¶РµРЅ РґСЂСѓРіРѕР№ РјРµС‚РѕРґ СЂРµРїРѕР·РёС‚РѕСЂРёСЏ РёР»Рё ->get()
        // Р’РѕР·РІСЂР°С‰Р°РµРј РїСѓСЃС‚СѓСЋ РєРѕР»Р»РµРєС†РёСЋ РёР»Рё РІС‹Р±СЂР°СЃС‹РІР°РµРј РёСЃРєР»СЋС‡РµРЅРёРµ, С‚.Рє. РјРµС‚РѕРґ РЅРµСЏСЃРµРЅ
        Log::warning('Deprecated method getAllProjectsForCurrentOrg called.');
        // return $this->projectRepository->getProjectsForOrganization($organizationId, -1)->items(); // РџСЂРёРјРµСЂ РѕР±С…РѕРґР° РїР°РіРёРЅР°С†РёРё
        return new Collection(); // Р’РѕР·РІСЂР°С‰Р°РµРј РїСѓСЃС‚СѓСЋ РєРѕР»Р»РµРєС†РёСЋ
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ Р°РєС‚РёРІРЅС‹Рµ РїСЂРѕРµРєС‚С‹ РґР»СЏ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     */
    public function getActiveProjectsForCurrentOrg(Request $request): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);
        return $this->projectRepository->getActiveProjects($organizationId);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїСЂРѕРµРєС‚С‹, РЅР°Р·РЅР°С‡РµРЅРЅС‹Рµ РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ РІ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     */
    public function getProjectsForUser(Request $request): Collection
    {
        $user = $request->user();
        if (!$user) {
             throw new BusinessLogicException('РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅ.', 401);
        }
        $userId = $user->id;
        $organizationId = $this->getCurrentOrgId($request);
        return $this->projectRepository->getProjectsForUser($userId, $organizationId);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРµС‚Р°Р»Рё РїСЂРѕРµРєС‚Р° РїРѕ ID (СЃ РѕС‚РЅРѕС€РµРЅРёСЏРјРё).
     * РџСЂРѕРІРµСЂСЏРµС‚ РїСЂРёРЅР°РґР»РµР¶РЅРѕСЃС‚СЊ РїСЂРѕРµРєС‚Р° С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     */
    public function getProjectDetails(int $id, Request $request): ?Project
    { 
        $project = $this->findProjectByIdForCurrentOrg($id, $request); // РСЃРїРѕР»СЊР·СѓРµРј СѓР¶Рµ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ РјРµС‚РѕРґ
        if (!$project) {
             return null;
        }
        // Р—Р°РіСЂСѓР¶Р°РµРј РЅСѓР¶РЅС‹Рµ СЃРІСЏР·Рё
        return $project->load(['materials', 'workTypes', 'users']); 
    }
    
    public function getProjectStatistics(int $id): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ.', 404);
        }

        try {
            // ===== РРЎРўРћР§РќРРљ РРЎРўРРќР«: РЎРљР›РђР” (warehouse_balances + warehouse_movements) =====
            // РЎС‚Р°С‚РёСЃС‚РёРєР° РїРѕ РјР°С‚РµСЂРёР°Р»Р°Рј - Р±РµСЂРµРј РёР· РґРІРёР¶РµРЅРёР№ СЃРєР»Р°РґР°, СЃРІСЏР·Р°РЅРЅС‹С… СЃ РїСЂРѕРµРєС‚РѕРј
            $materialStats = DB::table('warehouse_movements as wm')
                ->join('warehouse_balances as wb', function($join) {
                    $join->on('wm.warehouse_id', '=', 'wb.warehouse_id')
                         ->on('wm.material_id', '=', 'wb.material_id')
                         ->on('wm.organization_id', '=', 'wb.organization_id');
                })
                ->where('wm.project_id', $id)
                ->selectRaw("
                    COUNT(DISTINCT wm.material_id) as unique_materials_count,
                    SUM(CASE WHEN wm.movement_type = 'receipt' THEN wm.quantity ELSE 0 END) as total_received,
                    SUM(CASE WHEN wm.movement_type = 'write_off' THEN wm.quantity ELSE 0 END) as total_used,
                    SUM(CASE WHEN wm.movement_type = 'receipt' THEN (wm.quantity * wm.price) ELSE 0 END) as total_received_value,
                    SUM(CASE WHEN wm.movement_type = 'write_off' THEN (wm.quantity * wm.price) ELSE 0 END) as total_used_value
                ")
                ->first();
            
            // Р•СЃР»Рё РЅРµС‚ РґРІРёР¶РµРЅРёР№ РїРѕ РїСЂРѕРµРєС‚Сѓ, РїСЂРѕРІРµСЂСЏРµРј СЂР°СЃРїСЂРµРґРµР»РµРЅРёСЏ (РЅРѕ Р±РµР· С„РёРЅР°РЅСЃРѕРІС‹С… РґР°РЅРЅС‹С…)
            if (!$materialStats || $materialStats->unique_materials_count == 0) {
                $allocationStats = DB::table('warehouse_project_allocations as wpa')
                    ->join('warehouse_balances as wb', function($join) {
                        $join->on('wpa.warehouse_id', '=', 'wb.warehouse_id')
                             ->on('wpa.material_id', '=', 'wb.material_id')
                             ->on('wpa.organization_id', '=', 'wb.organization_id');
                    })
                    ->where('wpa.project_id', $id)
                    ->selectRaw("
                        COUNT(DISTINCT wpa.material_id) as unique_materials_count,
                        SUM(wpa.allocated_quantity) as total_allocated,
                        SUM(wpa.allocated_quantity * wb.unit_price) as allocated_value
                    ")
                    ->first();
                
                // РСЃРїРѕР»СЊР·СѓРµРј РґР°РЅРЅС‹Рµ СЂР°СЃРїСЂРµРґРµР»РµРЅРёР№, РµСЃР»Рё РµСЃС‚СЊ
                if ($allocationStats && $allocationStats->unique_materials_count > 0) {
                    $materialStats = (object)[
                        'unique_materials_count' => $allocationStats->unique_materials_count,
                        'total_received' => 0,
                        'total_used' => 0,
                        'total_received_value' => 0,
                        'total_used_value' => 0,
                    ];
                }
            }

            // РЎС‚Р°С‚РёСЃС‚РёРєР° РїРѕ РІС‹РїРѕР»РЅРµРЅРЅС‹Рј СЂР°Р±РѕС‚Р°Рј
            $workStats = DB::table('completed_works as cw')
                ->where('cw.project_id', $id)
                ->selectRaw("\n                    COUNT(*) as total_works_count,\n                    SUM(cw.quantity) as total_work_quantity,\n                    COUNT(DISTINCT cw.work_type_id) as unique_work_types_count,\n                    SUM(cw.total_amount) as total_work_cost\n                ")
                ->first();

            // РљРѕРјР°РЅРґР° РїСЂРѕРµРєС‚Р°
            $teamMembers = DB::table('project_user as pu')
                ->join('users as u', 'u.id', '=', 'pu.user_id')
                ->where('pu.project_id', $id)
                ->select(['u.id', 'u.name', 'pu.role'])
                ->get();

            $userStats = (object) ['assigned_users_count' => $teamMembers->count()];

            // РђРєС‚С‹ РІС‹РїРѕР»РЅРµРЅРЅС‹С… СЂР°Р±РѕС‚ РїРѕ РїСЂРѕРµРєС‚Сѓ
            // Р¤РёР»СЊС‚СЂСѓРµРј РЅР°РїСЂСЏРјСѓСЋ РїРѕ project_id РґР»СЏ РєРѕСЂСЂРµРєС‚РЅРѕР№ СЂР°Р±РѕС‚С‹ СЃ РјСѓР»СЊС‚РёРїСЂРѕРµРєС‚РЅС‹РјРё РєРѕРЅС‚СЂР°РєС‚Р°РјРё
            $acts = DB::table('contract_performance_acts as a')
                ->join('contracts as c', 'c.id', '=', 'a.contract_id')
                ->where('a.project_id', $id)
                ->select(['a.id', 'a.contract_id', 'a.act_document_number', 'a.act_date', 'a.amount', 'a.is_approved'])
                ->orderBy('a.act_date', 'desc')
                ->get();

            // РџРѕСЃР»РµРґРЅРёРµ РѕРїРµСЂР°С†РёРё - РРЎРўРћР§РќРРљ РРЎРўРРќР«: РЎРљР›РђР”
            $lastMaterialOperation = DB::table('warehouse_movements')
                ->where('project_id', $id)
                ->whereIn('movement_type', ['receipt', 'write_off'])
                ->orderBy('movement_date', 'desc')
                ->first(['movement_date', 'movement_type']);

            $lastWorkCompletion = DB::table('completed_works')
                ->where('project_id', $id)
                ->orderBy('completion_date', 'desc')
                ->first(['completion_date']);

            return [
                'project_id' => $id,
                'project_name' => $project->name,
                'materials' => [
                    'unique_materials_count' => $materialStats->unique_materials_count ?? 0,
                    'total_received' => $materialStats->total_received ?? 0,
                    'total_used' => $materialStats->total_used ?? 0,
                    'current_balance' => ($materialStats->total_received ?? 0) - ($materialStats->total_used ?? 0),
                    'total_received_value' => $materialStats->total_received_value ?? 0,
                    'total_used_value' => $materialStats->total_used_value ?? 0,
                    'last_operation_date' => $lastMaterialOperation->movement_date ?? null,
                    'last_operation_type' => $lastMaterialOperation->movement_type ?? null
                ],
                'works' => [
                    'total_works_count' => $workStats->total_works_count ?? 0,
                    'total_work_quantity' => $workStats->total_work_quantity ?? 0,
                    'unique_work_types_count' => $workStats->unique_work_types_count ?? 0,
                    'total_work_cost' => $workStats->total_work_cost ?? 0,
                    'last_completion_date' => $lastWorkCompletion->completion_date ?? null
                ],
                'team' => [
                    'assigned_users_count' => $userStats->assigned_users_count ?? 0,
                    'members' => $teamMembers,
                ],
                'performance_acts' => $acts,
                'project_info' => [
                    'start_date' => $project->start_date,
                    'end_date' => $project->end_date,
                    'status' => $project->status,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project statistics', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё СЃС‚Р°С‚РёСЃС‚РёРєРё РїСЂРѕРµРєС‚Р°.', 500);
        }
    }

    public function getProjectMaterials(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'allocated_quantity', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ.', 404);
        }

        try {
            // РЎРљР›РђР”РЎРљРђРЇ РЎРРЎРўР•РњРђ: РїРѕРєР°Р·С‹РІР°РµРј РјР°С‚РµСЂРёР°Р»С‹, СЂР°СЃРїСЂРµРґРµР»РµРЅРЅС‹Рµ РЅР° РїСЂРѕРµРєС‚ + РґРѕСЃС‚СѓРїРЅС‹Р№ РѕСЃС‚Р°С‚РѕРє РЅР° СЃРєР»Р°РґР°С…
            $query = DB::table('warehouse_project_allocations as wpa')
                ->join('materials as m', 'wpa.material_id', '=', 'm.id')
                ->join('organization_warehouses as w', 'wpa.warehouse_id', '=', 'w.id')
                ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
                ->leftJoin('users as u', 'wpa.allocated_by_user_id', '=', 'u.id')
                // РџРѕРґС‚СЏРіРёРІР°РµРј РѕР±С‰РёР№ РѕСЃС‚Р°С‚РѕРє РјР°С‚РµСЂРёР°Р»Р° РЅР° РІСЃРµС… СЃРєР»Р°РґР°С… РѕСЂРіР°РЅРёР·Р°С†РёРё
                ->leftJoin(DB::raw('(
                    SELECT 
                        wb.material_id,
                        wb.organization_id,
                        SUM(wb.available_quantity) as total_warehouse_available,
                        SUM(wb.available_quantity * wb.unit_price) as total_val
                    FROM warehouse_balances wb
                    JOIN organization_warehouses ow ON wb.warehouse_id = ow.id
                    WHERE ow.is_active = true
                    GROUP BY wb.material_id, wb.organization_id
                ) as warehouse_totals'), function($join) {
                    $join->on('wpa.material_id', '=', 'warehouse_totals.material_id')
                         ->on('wpa.organization_id', '=', 'warehouse_totals.organization_id');
                })
                ->where('wpa.project_id', $id)
                ->select([
                    'wpa.id as allocation_id',
                    'm.id as material_id',
                    'm.name as material_name',
                    'm.code as material_code',
                    'mu.short_name as unit',
                    'w.name as warehouse_name',
                    'w.id as warehouse_id',
                    'wpa.allocated_quantity as allocated_quantity',
                    DB::raw('COALESCE(warehouse_totals.total_warehouse_available, 0) as warehouse_available_total'),
                    DB::raw('CASE WHEN COALESCE(warehouse_totals.total_warehouse_available, 0) > 0 THEN warehouse_totals.total_val / warehouse_totals.total_warehouse_available ELSE COALESCE(m.default_price, 0) END as average_price'),
                    'wpa.allocated_at as last_operation_date',
                    'u.name as allocated_by',
                    'wpa.notes'
                ]);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('m.name', 'like', "%{$search}%")
                      ->orWhere('m.code', 'like', "%{$search}%")
                      ->orWhere('w.name', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['material_name', 'material_code', 'warehouse_name', 'allocated_quantity', 'warehouse_available_total', 'last_operation_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_operation_date';
            }

            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortBy, $sortDirection);

            $paginatedResults = $query->paginate($perPage);

            return [
                'data' => collect($paginatedResults->items())->map(function($item) {
                    $warehouseAvailable = (float)$item->warehouse_available_total;
                    $allocated = (float)$item->allocated_quantity;
                    
                    // РљР РРўРР§РќРћ: РџСЂРѕРІРµСЂСЏРµРј РІР°Р»РёРґРЅРѕСЃС‚СЊ РґР°РЅРЅС‹С…
                    // Р•СЃР»Рё РјР°С‚РµСЂРёР°Р» СЂР°СЃРїСЂРµРґРµР»РµРЅ, РЅРѕ РµРіРѕ РќР•Рў РЅР° СЃРєР»Р°РґРµ - СЌС‚Рѕ РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Рµ РґР°РЅРЅС‹Рµ!
                    $isValid = $warehouseAvailable > 0;
                    $hasWarning = !$isValid && $allocated > 0;
                    
                    return [
                        'allocation_id' => $item->allocation_id,
                        'material_id' => $item->material_id,
                        'material_name' => $item->material_name,
                        'material_code' => $item->material_code,
                        'unit' => $item->unit,
                        'warehouse_name' => $item->warehouse_name,
                        'warehouse_id' => $item->warehouse_id,
                        'allocated_quantity' => $allocated, // Р Р°СЃРїСЂРµРґРµР»РµРЅРѕ РЅР° РїСЂРѕРµРєС‚
                        'warehouse_available_total' => $warehouseAvailable, // Р”РѕСЃС‚СѓРїРЅРѕ РЅР° РІСЃРµС… СЃРєР»Р°РґР°С…
                        'average_price' => (float)$item->average_price,
                        'allocated_value' => $allocated * (float)$item->average_price,
                        'last_operation_date' => $item->last_operation_date,
                        'allocated_by' => $item->allocated_by,
                        'notes' => $item->notes,
                        // Р¤Р»Р°РіРё РІР°Р»РёРґРЅРѕСЃС‚Рё РґР°РЅРЅС‹С…
                        'is_valid' => $isValid,
                        'has_warning' => $hasWarning,
                        'warning_message' => $hasWarning ? 'РњР°С‚РµСЂРёР°Р» СЂР°СЃРїСЂРµРґРµР»РµРЅ РЅР° РїСЂРѕРµРєС‚, РЅРѕ РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ РЅР° СЃРєР»Р°РґРµ. РўСЂРµР±СѓРµС‚СЃСЏ РѕРїСЂРёС…РѕРґРѕРІР°РЅРёРµ!' : null,
                    ];
                }),
                'links' => [
                    'first' => $paginatedResults->url(1),
                    'last' => $paginatedResults->url($paginatedResults->lastPage()),
                    'prev' => $paginatedResults->previousPageUrl(),
                    'next' => $paginatedResults->nextPageUrl()
                ],
                'meta' => [
                    'current_page' => $paginatedResults->currentPage(),
                    'last_page' => $paginatedResults->lastPage(),
                    'per_page' => $paginatedResults->perPage(),
                    'total' => $paginatedResults->total(),
                    'from' => $paginatedResults->firstItem(),
                    'to' => $paginatedResults->lastItem()
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project materials', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё РјР°С‚РµСЂРёР°Р»РѕРІ РїСЂРѕРµРєС‚Р°.', 500);
        }
    }

    public function getProjectWorkTypes(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ.', 404);
        }

        try {
            $completedAggregates = DB::table('completed_works as cw')
                ->where('cw.project_id', $id)
                ->whereNull('cw.deleted_at')
                ->select([
                    'cw.work_type_id',
                    DB::raw('COUNT(cw.id) as works_count'),
                    DB::raw('SUM(COALESCE(cw.completed_quantity, cw.quantity, 0)) as completed_quantity'),
                    DB::raw('SUM(COALESCE(cw.total_amount, 0)) as total_cost'),
                    DB::raw('AVG(cw.price) as average_unit_price'),
                    DB::raw('MAX(cw.completion_date) as last_completion_date'),
                    DB::raw('COUNT(DISTINCT cw.user_id) as workers_count'),
                ])
                ->groupBy('cw.work_type_id');

            $plannedAggregates = DB::table('schedule_tasks as st')
                ->join('project_schedules as ps', 'st.schedule_id', '=', 'ps.id')
                ->where('ps.project_id', $id)
                ->whereNotNull('st.work_type_id')
                ->whereNull('st.deleted_at')
                ->whereNull('ps.deleted_at')
                ->select([
                    'st.work_type_id',
                    DB::raw('SUM(COALESCE(st.quantity, 0)) as planned_quantity'),
                ])
                ->groupBy('st.work_type_id');

            $query = DB::table('work_types as wt')
                ->leftJoinSub($completedAggregates, 'cw_summary', function ($join) {
                    $join->on('cw_summary.work_type_id', '=', 'wt.id');
                })
                ->leftJoinSub($plannedAggregates, 'plan_summary', function ($join) {
                    $join->on('plan_summary.work_type_id', '=', 'wt.id');
                })
                ->leftJoin('measurement_units as mu', 'wt.measurement_unit_id', '=', 'mu.id')
                ->whereNull('wt.deleted_at')
                ->where(function ($query) {
                    $query
                        ->whereNotNull('cw_summary.work_type_id')
                        ->orWhereNotNull('plan_summary.work_type_id');
                })
                ->select([
                    'wt.id as work_type_id',
                    'wt.name as work_type_name',
                    'wt.description as work_type_description',
                    'mu.short_name as unit',
                    DB::raw('COALESCE(plan_summary.planned_quantity, 0) as planned_quantity'),
                    DB::raw('COALESCE(cw_summary.completed_quantity, 0) as completed_quantity'),
                    DB::raw('COALESCE(cw_summary.completed_quantity, 0) as actual_quantity'),
                    DB::raw('COALESCE(cw_summary.completed_quantity, 0) as total_quantity'),
                    DB::raw('CASE WHEN COALESCE(plan_summary.planned_quantity, 0) > 0 THEN ROUND((COALESCE(cw_summary.completed_quantity, 0) * 1.0 / COALESCE(plan_summary.planned_quantity, 0)) * 100, 2) ELSE 0 END as completion_percentage'),
                    DB::raw('COALESCE(cw_summary.works_count, 0) as works_count'),
                    DB::raw('COALESCE(cw_summary.total_cost, 0) as total_cost'),
                    DB::raw('COALESCE(cw_summary.average_unit_price, 0) as average_unit_price'),
                    'cw_summary.last_completion_date',
                    DB::raw('COALESCE(cw_summary.workers_count, 0) as workers_count'),
                ]);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('wt.name', 'like', "%{$search}%")
                      ->orWhere('wt.description', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['work_type_name', 'works_count', 'planned_quantity', 'completed_quantity', 'total_quantity', 'total_cost', 'last_completion_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_completion_date';
            }

            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortBy, $sortDirection);

            $paginatedResults = $query->paginate($perPage);

            return [
                'data' => $paginatedResults->items(),
                'links' => [
                    'first' => $paginatedResults->url(1),
                    'last' => $paginatedResults->url($paginatedResults->lastPage()),
                    'prev' => $paginatedResults->previousPageUrl(),
                    'next' => $paginatedResults->nextPageUrl()
                ],
                'meta' => [
                    'current_page' => $paginatedResults->currentPage(),
                    'last_page' => $paginatedResults->lastPage(),
                    'per_page' => $paginatedResults->perPage(),
                    'total' => $paginatedResults->total(),
                    'from' => $paginatedResults->firstItem(),
                    'to' => $paginatedResults->lastItem()
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project work types', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё РІРёРґРѕРІ СЂР°Р±РѕС‚ РїСЂРѕРµРєС‚Р°.', 500);
        }
    }

    /**
     * Р”РѕР±Р°РІРёС‚СЊ РґРѕС‡РµСЂРЅСЋСЋ РѕСЂРіР°РЅРёР·Р°С†РёСЋ Рє РїСЂРѕРµРєС‚Сѓ.
     */
    public function addOrganizationToProject(
        int $projectId, 
        int $organizationId, 
        ProjectOrganizationRole $role,
        Request $request
    ): void {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->attach($project, $organizationId, $role, $request->user());
    }

    public function attachOrganizationToProjectEntity(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $role,
        ?User $user = null
    ): void {
        $this->projectParticipantService->attach($project, $organizationId, $role, $user);
    }

    private function ensureContractorExists(int $forOrgId, int $sourceOrgId): void
    {
        $sourceOrg = \App\Models\Organization::find($sourceOrgId);
        if (!$sourceOrg) {
            return;
        }

        $exists = \App\Models\Contractor::where('organization_id', $forOrgId)
            ->where('source_organization_id', $sourceOrgId)
            ->exists();

        if ($exists) {
            return;
        }

        \App\Models\Contractor::create([
            'organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'name' => $sourceOrg->name,
            'inn' => $sourceOrg->tax_number,
            'legal_address' => $sourceOrg->address,
            'phone' => $sourceOrg->phone,
            'email' => $sourceOrg->email,
            'contractor_type' => \App\Models\Contractor::TYPE_INVITED_ORGANIZATION,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn'],
                'sync_interval_hours' => 24,
            ],
        ]);

        $this->logging->business('Contractor created from project participant', [
            'for_organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'contractor_name' => $sourceOrg->name,
        ]);
    }

    /**
     * РЈРґР°Р»РёС‚СЊ РѕСЂРіР°РЅРёР·Р°С†РёСЋ РёР· РїСЂРѕРµРєС‚Р°.
     */
    public function removeOrganizationFromProject(int $projectId, int $organizationId, Request $request): void
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->remove($project, $organizationId, $request->user());
    }
    
    /**
     * РР·РјРµРЅРёС‚СЊ СЂРѕР»СЊ РѕСЂРіР°РЅРёР·Р°С†РёРё РІ РїСЂРѕРµРєС‚Рµ.
     */
    public function updateOrganizationRole(
        int $projectId, 
        int $organizationId, 
        ProjectOrganizationRole $newRole,
        Request $request
    ): void {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->updateRole($project, $organizationId, $newRole, $request->user());
    }

    public function updateOrganizationRoleForProjectEntity(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $newRole,
        ?User $user = null
    ): void {
        $this->projectParticipantService->updateRole($project, $organizationId, $newRole, $user);
    }

    public function setOrganizationActiveState(Project $project, int $organizationId, bool $isActive): void
    {
        $this->projectParticipantService->setActiveState($project, $organizationId, $isActive);
    }

    private function assertCustomerRoleAvailable(
        Project $project,
        ProjectOrganizationRole $role,
        ?int $organizationId = null
    ): void {
        $this->projectParticipantService->enforceUniqueCustomer($project, $role, $organizationId);
    }

    private function resolveLegacyProjectRoleValue(ProjectOrganizationRole $role): string
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER => 'owner',
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::GENERAL_CONTRACTOR => 'contractor',
            ProjectOrganizationRole::SUBCONTRACTOR => 'child_contractor',
            ProjectOrganizationRole::CUSTOMER,
            ProjectOrganizationRole::CONSTRUCTION_SUPERVISION,
            ProjectOrganizationRole::DESIGNER,
            ProjectOrganizationRole::OBSERVER,
            ProjectOrganizationRole::PARENT_ADMINISTRATOR => 'observer',
        };
    }

    private function resolveOrganizationRoleForProject(
        Project $project,
        int $organizationId,
        bool $includeInactive = false
    ): ?ProjectOrganizationRole
    {
        if ($organizationId === (int) $project->organization_id) {
            return ProjectOrganizationRole::OWNER;
        }

        $pivotQuery = ProjectOrganization::query()
            ->where('project_id', $project->id)
            ->where('organization_id', $organizationId);

        if (!$includeInactive) {
            $pivotQuery->where('is_active', true);
        }

        $pivot = $pivotQuery->first();

        if (!$pivot instanceof ProjectOrganization) {
            return null;
        }

        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');
        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    private function resolveProjectRoleFromPivot(ProjectOrganization $pivot): ?ProjectOrganizationRole
    {
        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');
        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїРѕР»РЅСѓСЋ РёРЅС„РѕСЂРјР°С†РёСЋ РїРѕ РїСЂРѕРµРєС‚Сѓ: С„РёРЅР°РЅСЃС‹, СЃС‚Р°С‚РёСЃС‚РёРєР°, СЂР°Р·Р±РёРІРєР° РїРѕ РѕСЂРіР°РЅРёР·Р°С†РёСЏРј.
     */
    public function getFullProjectDetails(int $projectId, Request $request): array
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ РёР»Рё РЅРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.', 404);
        }

        // Р—Р°РіСЂСѓР¶Р°РµРј РѕСЂРіР°РЅРёР·Р°С†РёРё Рё РєРѕРЅС‚СЂР°РєС‚С‹ СЃ Р°РєС‚Р°РјРё/РїР»Р°С‚РµР¶Р°РјРё
        $project->load([
            'organizations:id,name',
            'contracts:id,project_id,total_amount,status',
            'contracts.performanceActs:id,contract_id,amount,is_approved',
            'contracts.payments:id,invoiceable_id,invoiceable_type,paid_amount',
        ]);

        // РћР±С‰РёРµ СЃСѓРјРјС‹
        $totalContractsAmount = $project->contracts->sum('total_amount');
        $totalPerformanceActsAmount = $project->contracts->flatMap(fn($c) => $c->performanceActs)->where('is_approved', true)->sum('amount');
        $totalPaymentsAmount = $project->contracts->flatMap(fn($c) => $c->payments)->sum('paid_amount');

        // РЎСѓРјРјР° РІС‹РїРѕР»РЅРµРЅРЅС‹С… СЂР°Р±РѕС‚ Рё РјР°С‚РµСЂРёР°Р»РѕРІ
        $completedWorksQuery = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->selectRaw('organization_id, COUNT(*) as works_count, SUM(total_amount) as works_amount')
            ->groupBy('organization_id')
            ->get();

        $materialsQuery = DB::table('completed_work_materials as cwm')
            ->join('completed_works as cw', 'cw.id', '=', 'cwm.completed_work_id')
            ->where('cw.project_id', $projectId)
            ->selectRaw('cw.organization_id, SUM(cwm.total_amount) as materials_amount')
            ->groupBy('cw.organization_id')
            ->get();

        // Р¤РѕСЂРјРёСЂСѓРµРј СЃР»РѕРІР°СЂРё РґР»СЏ Р±С‹СЃС‚СЂРѕРіРѕ РґРѕСЃС‚СѓРїР°
        $worksByOrg = $completedWorksQuery->keyBy('organization_id');
        $materialsByOrg = $materialsQuery->keyBy('organization_id');

        $organizationsStats = $project->organizations->map(function ($org) use ($worksByOrg, $materialsByOrg) {
            $works = $worksByOrg[$org->id] ?? null;
            $materials = $materialsByOrg[$org->id] ?? null;

            return [
                'id' => $org->id,
                'name' => $org->name,
                'works_count' => (int) ($works->works_count ?? 0),
                'works_amount' => (float) ($works->works_amount ?? 0),
                'materials_amount' => (float) ($materials->materials_amount ?? 0),
                'total_cost' => (float) (($works->works_amount ?? 0) + ($materials->materials_amount ?? 0)),
            ];
        })->toArray();

        // РћР±С‰Р°СЏ СЃС‚Р°С‚РёСЃС‚РёРєР° РїРѕ РІС‹РїРѕР»РЅРµРЅРЅС‹Рј СЂР°Р±РѕС‚Р°Рј Рё РјР°С‚РµСЂРёР°Р»Р°Рј
        $totalWorksAmount = array_sum(array_column($organizationsStats, 'works_amount'));
        $totalMaterialsAmount = array_sum(array_column($organizationsStats, 'materials_amount'));

        $analytics = [
            'financial' => [
                'contracts_total_amount' => (float) $totalContractsAmount,
                'performed_amount_by_acts' => (float) $totalPerformanceActsAmount,
                'received_payments_amount' => (float) $totalPaymentsAmount,
                'works_total_amount' => (float) $totalWorksAmount,
                'materials_total_amount' => (float) $totalMaterialsAmount,
                'overall_cost' => (float) ($totalWorksAmount + $totalMaterialsAmount),
            ],
            'counts' => [
                'organizations' => count($organizationsStats),
                'contracts' => $project->contracts->count(),
                'performance_acts' => $project->contracts->flatMap(fn($c) => $c->performanceActs)->count(),
            ],
        ];

        return [
            'project' => $project,
            'analytics' => $analytics,
            'organizations_stats' => $organizationsStats,
        ];
    }
} 
