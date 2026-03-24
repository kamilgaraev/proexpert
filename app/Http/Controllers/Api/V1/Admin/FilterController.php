<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractTypeEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class FilterController extends Controller
{
    public function contractFilters(Request $request): JsonResponse
    {
        return $this->runAction(
            $request,
            'contract_filters',
            function (int $organizationId) {
                return AdminResponse::success([
                    'statuses' => [
                        ['value' => ContractStatusEnum::DRAFT->value, 'label' => 'Черновик'],
                        ['value' => ContractStatusEnum::ACTIVE->value, 'label' => 'Активный'],
                        ['value' => ContractStatusEnum::COMPLETED->value, 'label' => 'Завершен'],
                        ['value' => ContractStatusEnum::ON_HOLD->value, 'label' => 'На паузе'],
                        ['value' => ContractStatusEnum::TERMINATED->value, 'label' => 'Расторгнут'],
                    ],
                    'types' => [
                        ['value' => ContractTypeEnum::CONTRACT->value, 'label' => 'Контракт'],
                        ['value' => ContractTypeEnum::AGREEMENT->value, 'label' => 'Соглашение'],
                        ['value' => ContractTypeEnum::SPECIFICATION->value, 'label' => 'Спецификация'],
                    ],
                    'work_type_categories' => array_map(
                        fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                        ContractWorkTypeCategoryEnum::cases()
                    ),
                    'projects' => Project::where('organization_id', $organizationId)
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($project) => [
                            'value' => $project->id,
                            'label' => $project->name,
                        ]),
                    'contractors' => Contractor::where('organization_id', $organizationId)
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($contractor) => [
                            'value' => $contractor->id,
                            'label' => $contractor->name,
                        ]),
                ]);
            }
        );
    }

    public function completedWorkFilters(Request $request): JsonResponse
    {
        return $this->runAction(
            $request,
            'completed_work_filters',
            function (int $organizationId) {
                return AdminResponse::success([
                    'statuses' => [
                        ['value' => 'pending', 'label' => 'Ожидает подтверждения'],
                        ['value' => 'confirmed', 'label' => 'Подтверждено'],
                        ['value' => 'rejected', 'label' => 'Отклонено'],
                    ],
                    'projects' => Project::where('organization_id', $organizationId)
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($project) => [
                            'value' => $project->id,
                            'label' => $project->name,
                        ]),
                    'contracts' => Contract::where('organization_id', $organizationId)
                        ->with('project:id,name')
                        ->select('id', 'number', 'project_id')
                        ->orderBy('number')
                        ->get()
                        ->map(fn ($contract) => [
                            'value' => $contract->id,
                            'label' => $contract->number . ($contract->project ? ' (' . $contract->project->name . ')' : ''),
                        ]),
                    'work_types' => WorkType::where('organization_id', $organizationId)
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($workType) => [
                            'value' => $workType->id,
                            'label' => $workType->name,
                        ]),
                    'users' => User::whereHas('organizations', function ($query) use ($organizationId) {
                        $query->where('organizations.id', $organizationId);
                    })
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($user) => [
                            'value' => $user->id,
                            'label' => $user->name,
                        ]),
                    'contractors' => Contractor::where('organization_id', $organizationId)
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get()
                        ->map(fn ($contractor) => [
                            'value' => $contractor->id,
                            'label' => $contractor->name,
                        ]),
                ]);
            }
        );
    }

    public function quickStats(Request $request): JsonResponse
    {
        return $this->runAction(
            $request,
            'quick_stats',
            function (int $organizationId) {
                $contractStats = Contract::where('organization_id', $organizationId)
                    ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total_amount')
                    ->groupBy('status')
                    ->get()
                    ->keyBy('status');

                $workStats = DB::table('completed_works')
                    ->where('organization_id', $organizationId)
                    ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total_amount')
                    ->groupBy('status')
                    ->get()
                    ->keyBy('status');

                return AdminResponse::success([
                    'contracts' => [
                        'draft' => [
                            'count' => $contractStats->get('draft')?->count ?? 0,
                            'amount' => (float) ($contractStats->get('draft')?->total_amount ?? 0),
                        ],
                        'active' => [
                            'count' => $contractStats->get('active')?->count ?? 0,
                            'amount' => (float) ($contractStats->get('active')?->total_amount ?? 0),
                        ],
                        'completed' => [
                            'count' => $contractStats->get('completed')?->count ?? 0,
                            'amount' => (float) ($contractStats->get('completed')?->total_amount ?? 0),
                        ],
                        'on_hold' => [
                            'count' => $contractStats->get('on_hold')?->count ?? 0,
                            'amount' => (float) ($contractStats->get('on_hold')?->total_amount ?? 0),
                        ],
                        'terminated' => [
                            'count' => $contractStats->get('terminated')?->count ?? 0,
                            'amount' => (float) ($contractStats->get('terminated')?->total_amount ?? 0),
                        ],
                    ],
                    'completed_works' => [
                        'pending' => [
                            'count' => $workStats->get('pending')?->count ?? 0,
                            'amount' => (float) ($workStats->get('pending')?->total_amount ?? 0),
                        ],
                        'confirmed' => [
                            'count' => $workStats->get('confirmed')?->count ?? 0,
                            'amount' => (float) ($workStats->get('confirmed')?->total_amount ?? 0),
                        ],
                        'rejected' => [
                            'count' => $workStats->get('rejected')?->count ?? 0,
                            'amount' => (float) ($workStats->get('rejected')?->total_amount ?? 0),
                        ],
                    ],
                ]);
            }
        );
    }

    private function runAction(Request $request, string $action, callable $callback): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(
                trans_message('filters.organization_context_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            return $callback($organizationId);
        } catch (Throwable $e) {
            Log::error("filters.{$action}.error", [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message("filters.{$action}_error"),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
