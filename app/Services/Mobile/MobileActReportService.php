<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Exceptions\BusinessLogicException;
use App\Models\ActFieldConfirmation;
use App\Models\ContractPerformanceAct;
use App\Models\User;
use App\Services\ActReport\ActFieldConfirmationService;
use App\Services\ActReport\ActReportAccessService;
use App\Services\ActReport\ActReportFileService;
use App\Services\ActReport\ActReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MobileActReportService
{
    private const PERMISSION_FIELD_CONFIRM = 'act_reports.field_confirm';

    public function __construct(
        private readonly ActReportAccessService $access,
        private readonly ActReportService $acts,
        private readonly ActReportFileService $files,
        private readonly ActFieldConfirmationService $confirmations,
        private readonly MobileProjectAccessResolver $projects,
    ) {}

    /** @return array<string, mixed> */
    public function index(Request $request, array $filters): array
    {
        $organizationId = $this->access->currentOrganizationId($request);
        $user = $request->user();
        if (! $user instanceof User) {
            throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
        }
        $selectedProjectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $projectIds = $this->projects->ids($user, $organizationId);
        $hasOrganizationPermission = $user->can(ActReportAccessService::PERMISSION_VIEW, ['organization_id' => $organizationId]);
        if ($selectedProjectId !== null) {
            try {
                $this->projects->assert($user, $organizationId, $selectedProjectId, trans_message('act_reports.act_not_found'));
            } catch (\DomainException $exception) {
                throw new BusinessLogicException(trans_message('act_reports.act_not_found'), 404, $exception);
            }
            $projectIds = [$selectedProjectId];
            if (! $hasOrganizationPermission && ! $this->hasContextPermission(
                $user,
                ActReportAccessService::PERMISSION_VIEW,
                $organizationId,
                $selectedProjectId
            )) {
                throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
            }
        } elseif (! $hasOrganizationPermission) {
            $projectIds = array_values(array_filter($projectIds, fn (int $projectId): bool => $this->hasContextPermission(
                $user,
                ActReportAccessService::PERMISSION_VIEW,
                $organizationId,
                $projectId
            )));
        }
        if ($projectIds === [] && ! $hasOrganizationPermission) {
            throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
        }
        $filters['project_ids'] = $projectIds;
        $filters['include_projectless'] = $selectedProjectId === null && $hasOrganizationPermission;
        unset($filters['project_id']);
        $page = $this->acts->getActsList($organizationId, $filters, (int) ($filters['per_page'] ?? 20));
        $confirmationActIds = $user instanceof User
            ? ActFieldConfirmation::query()
                ->whereIn('act_id', array_map(static fn (ContractPerformanceAct $act): int => (int) $act->id, $page->items()))
                ->where('user_id', $user->id)
                ->pluck('act_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : [];

        return [
            'items' => collect($page->items())->map(fn (ContractPerformanceAct $act): array => $this->brief(
                $act,
                $user,
                alreadyConfirmed: in_array((int) $act->id, $confirmationActIds, true),
                confirmationPreloaded: true
            ))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(Request $request, int $act): array
    {
        $organizationId = $this->access->currentOrganizationId($request);
        $record = $this->access->resolveAccessibleAct($request, $act);
        $this->assertRecordPermission($request, $record, ActReportAccessService::PERMISSION_VIEW, $organizationId);
        $record->loadMissing(['contract.project', 'contract.contractor', 'files', 'completedWorks', 'lines']);

        return $this->brief($record, $request->user(), true);
    }

    public function downloadFile(Request $request, int $act, int $file): StreamedResponse
    {
        $organizationId = $this->access->currentOrganizationId($request);
        $record = $this->access->resolveAccessibleAct($request, $act);
        $this->assertRecordPermission($request, $record, ActReportAccessService::PERMISSION_VIEW, $organizationId);

        return $this->files->download($record, $file);
    }

    /** @param array{signature_data:string, idempotency_key:string} $payload @return array{data:array<string,mixed>,created:bool} */
    public function fieldConfirm(Request $request, int $act, array $payload): array
    {
        $organizationId = $this->access->currentOrganizationId($request);
        $user = $request->user();
        if (! $user instanceof User) {
            throw new BusinessLogicException(trans_message('auth.mobile_access_denied'), 403);
        }
        $record = $this->access->resolveAccessibleAct($request, $act);
        $this->assertRecordPermission($request, $record, self::PERMISSION_FIELD_CONFIRM, $organizationId);

        return $this->confirmations->confirm(
            $record,
            $user,
            $organizationId,
            $payload['signature_data'],
            $payload['idempotency_key']
        );
    }

    /** @return array<string, mixed> */
    private function brief(
        ContractPerformanceAct $act,
        ?User $user,
        bool $detailed = false,
        bool $alreadyConfirmed = false,
        bool $confirmationPreloaded = false
    ): array {
        $organizationId = (int) ($act->contract?->organization_id ?? 0);
        $canConfirm = $user instanceof User
            && $this->canAccessRecord($user, $act, self::PERMISSION_FIELD_CONFIRM)
            && ! ($confirmationPreloaded
                ? $alreadyConfirmed
                : ActFieldConfirmation::query()->where('act_id', $act->id)->where('user_id', $user->id)->exists());
        $data = [
            'id' => (int) $act->id,
            'act_document_number' => $act->act_document_number,
            'number' => $act->act_document_number,
            'act_date' => $act->act_date?->format('Y-m-d'),
            'date' => $act->act_date?->format('Y-m-d'),
            'period_start' => $act->period_start?->format('Y-m-d'),
            'period_end' => $act->period_end?->format('Y-m-d'),
            'status' => $act->status,
            'status_label' => $act->status,
            'amount' => $act->amount,
            'currency' => $act->currency,
            'description' => $act->description,
            'created_at' => $act->created_at?->toISOString(),
            'project' => $act->contract?->project ? [
                'id' => (int) $act->contract->project->id,
                'name' => $act->contract->project->name,
            ] : null,
            'project_name' => $act->contract?->project?->name,
            'contractor_name' => $act->contract?->contractor?->name,
            'contract' => $act->contract ? [
                'id' => (int) $act->contract->id,
                'number' => $act->contract->number,
                'contractor_name' => $act->contract->contractor?->name,
            ] : null,
            'capabilities' => ['can_field_confirm' => $canConfirm],
        ];
        if (! $detailed) {
            return $data;
        }

        $data['files'] = $act->files->map(fn ($file): array => [
            'id' => (int) $file->id,
            'name' => $file->original_name ?: $file->name,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'category' => $file->category,
            'download_url' => route('api.v1.mobile.acts.files.download', ['act' => $act->id, 'file' => $file->id]),
        ])->values()->all();
        $data['lines'] = $act->lines->map(fn ($line): array => [
            'id' => (int) $line->id,
            'name' => $line->title ?? '',
            'quantity' => $line->quantity,
            'unit' => $line->unit ?? null,
            'amount' => $line->amount,
        ])->values()->all();
        $data['field_confirmations'] = ActFieldConfirmation::query()
            ->where('act_id', $act->id)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (ActFieldConfirmation $confirmation): array => [
                'id' => (int) $confirmation->id,
                'user_id' => (int) $confirmation->user_id,
                'user_name' => $confirmation->user?->name,
                'file_id' => (int) $confirmation->file_id,
                'confirmed_at' => $confirmation->confirmed_at?->toISOString(),
                'evidence_type' => 'field_acceptance',
                'legal_signature' => false,
            ])->all();

        return $data;
    }

    private function assertRecordPermission(Request $request, ContractPerformanceAct $act, string $permission, int $organizationId): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->canAccessRecord($user, $act, $permission)) {
            throw new BusinessLogicException(trans_message('act_reports.act_not_found'), 404);
        }
        $contractOrganizationId = (int) ($act->contract?->organization_id ?? 0);
        if ($contractOrganizationId !== $organizationId) {
            throw new BusinessLogicException(trans_message('act_reports.act_not_found'), 404);
        }
    }

    private function canAccessRecord(User $user, ContractPerformanceAct $act, string $permission): bool
    {
        $organizationId = (int) ($act->contract?->organization_id ?? 0);
        $projectId = $act->project_id === null ? $act->contract?->project_id : $act->project_id;
        if ($organizationId <= 0) {
            return false;
        }
        if ($projectId === null) {
            return $user->can($permission, ['organization_id' => $organizationId]);
        }
        if (! $this->projects->query($user, $organizationId)->whereKey((int) $projectId)->exists()) {
            return false;
        }

        return $this->hasContextPermission($user, $permission, $organizationId, (int) $projectId);
    }

    private function hasContextPermission(User $user, string $permission, int $organizationId, int $projectId): bool
    {
        return $user->can($permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])
            || $user->can($permission, ['organization_id' => $organizationId]);
    }
}
