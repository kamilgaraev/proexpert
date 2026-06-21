<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\BusinessModules\Features\Budgeting\Services\BudgetVersionService;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalLineItem;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\PresaleEstimates\Exceptions\PresaleEstimateBudgetTransferException;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimate;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateBudgetTransferOperation;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateLineItem;
use App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateVersion;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

use function trans_message;

final class PresaleEstimateBudgetConversionService
{
    private const SOURCE_TYPES = [
        'presale_estimate',
        'commercial_proposal',
        'tender',
        'crm_deal',
    ];

    private const EXCLUDED_LINE_TYPES = [
        'summary',
        'margin',
        'discount',
    ];

    public function __construct(
        private readonly BudgetVersionService $budgetVersionService,
        private readonly BudgetLineService $budgetLineService,
        private readonly AuthorizationService $authorization,
        private readonly LoggingService $logging
    ) {}

    public function preview(int $organizationId, User $user, array $data): array
    {
        return $this->buildPreview($organizationId, $user, $data, false);
    }

    public function validateTransfer(int $organizationId, User $user, array $data): array
    {
        return $this->buildPreview($organizationId, $user, $data, true);
    }

    public function convert(int $organizationId, User $user, array $data): array
    {
        if (($data['confirmed'] ?? false) !== true) {
            throw new PresaleEstimateBudgetTransferException(
                trans_message('presale_estimates.budget_transfer.errors.confirmation_required'),
                422,
                [[
                    'key' => 'confirmation_required',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.confirmation_required'),
                ]]
            );
        }

        $idempotencyKey = (string) $data['idempotency_key'];
        $payloadHash = $this->payloadHash($data);

        return DB::transaction(function () use ($organizationId, $user, $data, $idempotencyKey, $payloadHash): array {
            $source = $this->resolveSource($organizationId, $user, $data, true);
            $target = $this->resolveTarget($organizationId, $user, $data, $source, false);

            $operation = PresaleEstimateBudgetTransferOperation::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($operation instanceof PresaleEstimateBudgetTransferOperation) {
                if ($operation->payload_hash !== $payloadHash) {
                    throw new PresaleEstimateBudgetTransferException(
                        trans_message('presale_estimates.budget_transfer.errors.idempotency_conflict'),
                        409,
                        [[
                            'key' => 'idempotency_conflict',
                            'label' => trans_message('presale_estimates.budget_transfer.blockers.idempotency_conflict'),
                        ]]
                    );
                }

                if ($operation->status === 'completed') {
                    return array_merge($operation->result_snapshot ?? [], [
                        'status' => 'already_converted',
                        'idempotent_replay' => true,
                    ]);
                }
            }

            $completedOperation = $this->completedOperationForTarget($organizationId, $source, $target);
            if ($completedOperation instanceof PresaleEstimateBudgetTransferOperation) {
                return array_merge($completedOperation->result_snapshot ?? [], [
                    'status' => 'already_converted',
                    'idempotent_replay' => false,
                ]);
            }

            $preview = $this->buildPreview($organizationId, $user, $data, true, $source, $target);

            if (! $preview['ready_to_convert']) {
                throw new PresaleEstimateBudgetTransferException(
                    trans_message('presale_estimates.budget_transfer.errors.validation_failed'),
                    409,
                    $preview['blockers'],
                    $preview['warnings']
                );
            }

            if (! $operation instanceof PresaleEstimateBudgetTransferOperation) {
                $operation = PresaleEstimateBudgetTransferOperation::query()->create([
                    'organization_id' => $organizationId,
                    'source_type' => $source['source_type'],
                    'source_id' => $source['source_id'],
                    'presale_estimate_id' => $source['presale_estimate']?->id,
                    'presale_estimate_version_id' => $source['presale_version']?->id,
                    'project_id' => $target['project']->id,
                    'contract_id' => $target['contract']->id,
                    'budget_version_id' => $target['budget_version']?->id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'preview_hash' => $preview['preview_hash'],
                    'status' => 'started',
                    'created_by_user_id' => $user->id,
                    'result_snapshot' => [],
                ]);
            }

            try {
                $version = $target['budget_version'] instanceof BudgetVersion
                    ? $target['budget_version']
                    : $this->createBudgetVersion($organizationId, $user, $preview['target']['create_budget_version']);

                $rows = $this->normalizedBudgetRows($organizationId, $preview, $version, $operation->id, $user->id);

                $this->budgetLineService->writeNormalizedRows(
                    $version,
                    $rows,
                    'append_lines',
                    BudgetPeriodClosureService::OPERATION_BUDGET_IMPORT
                );

                $version = $version->refresh()->load(['period', 'scenario']);
                $result = $this->buildConvertResult($operation->id, $source, $preview, $version, count($rows));

                $operation->update([
                    'status' => 'completed',
                    'budget_version_id' => $version->id,
                    'result_snapshot' => $result,
                    'completed_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);

                $this->logging->audit('presale_estimates.budget_transfer.completed', [
                    'organization_id' => $organizationId,
                    'source_type' => $source['source_type'],
                    'source_id' => $source['source_id'],
                    'presale_estimate_id' => $source['presale_estimate']?->id,
                    'project_id' => $preview['target']['project']['id'],
                    'contract_id' => $preview['target']['contract']['id'],
                    'budget_version_id' => $version->id,
                    'budget_version_uuid' => $version->uuid,
                    'lines_count' => count($rows),
                    'performed_by' => $user->id,
                ]);

                $this->logging->business('presale_estimates.budget_transfer.lines_created', [
                    'organization_id' => $organizationId,
                    'source_type' => $source['source_type'],
                    'source_id' => $source['source_id'],
                    'budget_version_id' => $version->id,
                    'lines_count' => count($rows),
                ]);

                return $result;
            } catch (Throwable $exception) {
                $operation->update([
                    'status' => 'failed',
                    'error_code' => 'budget_transfer_failed',
                    'error_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        });
    }

    private function buildPreview(
        int $organizationId,
        User $user,
        array $data,
        bool $includeAuthorization,
        ?array $resolvedSource = null,
        ?array $resolvedTarget = null
    ): array {
        $source = $resolvedSource ?? $this->resolveSource($organizationId, $user, $data, false);
        $target = $resolvedTarget ?? $this->resolveTarget($organizationId, $user, $data, $source, true);
        $rows = $this->previewRows($organizationId, $source, $data, $target);
        $warnings = $target['warnings'];
        $blockers = $target['blockers'];

        foreach ($rows as $row) {
            foreach ($row['blockers'] as $blocker) {
                $blockers[] = $blocker;
            }

            foreach ($row['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }

        if (! $source['amount_visible']) {
            $blockers[] = [
                'key' => 'amount_permission_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.amount_permission_required'),
            ];
        }

        if ($source['rows'] === []) {
            $blockers[] = [
                'key' => 'source_rows_missing',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.source_rows_missing'),
            ];
        }

        if (count(array_filter($rows, static fn (array $row): bool => $row['included'] === true)) === 0) {
            $blockers[] = [
                'key' => 'included_rows_missing',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.included_rows_missing'),
            ];
        }

        if ($includeAuthorization) {
            $this->appendAuthorizationBlockers($organizationId, $user, $data, $target, $blockers);
        }

        $blockers = $this->uniqueMessages($blockers);
        $warnings = $this->uniqueMessages($warnings);

        $preview = [
            'source' => $source['summary'],
            'target' => $this->targetToArray($target),
            'amount' => [
                'amount_visible' => $source['amount_visible'],
                'value' => $source['amount_visible'] ? $this->decimalString($source['amount']) : null,
                'currency' => $source['currency'],
            ],
            'rows' => $rows,
            'summary' => $this->summary($rows, $source['currency'], $source['amount_visible']),
            'options' => $this->options($organizationId, (string) $target['budget_kind']),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'ready_to_convert' => $blockers === [],
        ];

        $preview['preview_hash'] = $this->previewHash($preview);

        if (! empty($data['preview_hash']) && $data['preview_hash'] !== $preview['preview_hash']) {
            $preview['blockers'][] = [
                'key' => 'preview_changed',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.preview_changed'),
            ];
            $preview['ready_to_convert'] = false;
        }

        return $preview;
    }

    private function completedOperationForTarget(
        int $organizationId,
        array $source,
        array $target
    ): ?PresaleEstimateBudgetTransferOperation {
        if (! $target['project'] instanceof Project || ! $target['contract'] instanceof Contract) {
            return null;
        }

        $query = PresaleEstimateBudgetTransferOperation::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $source['source_type'])
            ->where('source_id', $source['source_id'])
            ->where('project_id', $target['project']->id)
            ->where('contract_id', $target['contract']->id)
            ->where('status', 'completed');

        if ($target['budget_version'] instanceof BudgetVersion) {
            $query->where('budget_version_id', $target['budget_version']->id);
        }

        return $query->lockForUpdate()->first();
    }

    private function resolveSource(int $organizationId, User $user, array $data, bool $lock): array
    {
        [$sourceType, $sourceId] = $this->sourceSelector($data);

        return match ($sourceType) {
            'presale_estimate' => $this->presaleSource($organizationId, $user, $sourceId, null, null, null, $lock),
            'commercial_proposal' => $this->commercialProposalSource($organizationId, $user, $sourceId, $lock),
            'tender' => $this->tenderSource($organizationId, $user, $sourceId, $lock),
            'crm_deal' => $this->crmDealSource($organizationId, $user, $sourceId, $lock),
            default => throw new PresaleEstimateBudgetTransferException(
                trans_message('presale_estimates.budget_transfer.errors.source_required'),
                422,
                [[
                    'key' => 'source_required',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.source_required'),
                ]]
            ),
        };
    }

    private function commercialProposalSource(int $organizationId, User $user, string $proposalId, bool $lock): array
    {
        $proposal = $this->proposalQuery($organizationId, $lock)
            ->whereKey($proposalId)
            ->firstOrFail();

        if ($proposal->presale_estimate_id) {
            $estimate = $this->presaleQuery($organizationId, $lock)
                ->whereKey($proposal->presale_estimate_id)
                ->first();

            if ($estimate instanceof PresaleEstimate) {
                return $this->presaleSource($organizationId, $user, $estimate->id, $proposal, null, null, $lock);
            }
        }

        $version = $proposal->acceptedVersion ?? $proposal->currentVersion;
        $rows = $version instanceof CommercialProposalVersion
            ? $this->commercialProposalRows($proposal, $version)
            : [];
        $amount = $this->commercialProposalAmount($proposal, $version, $rows);

        if ($rows === [] && $amount !== null) {
            $rows[] = $this->fallbackTotalRow('commercial_proposal', $proposal->id, $proposal->title, $amount, (string) $proposal->currency);
        }

        return [
            'source_type' => 'commercial_proposal',
            'source_id' => $proposal->id,
            'presale_estimate' => null,
            'presale_version' => null,
            'commercial_proposal' => $proposal,
            'commercial_proposal_version' => $version,
            'tender' => null,
            'crm_deal' => null,
            'project_id' => $proposal->project_id,
            'contract_id' => $proposal->contract_id,
            'currency' => $proposal->currency ?? 'RUB',
            'amount_visible' => $this->canAny($user, $proposal->organization_id, ['commercial_proposals.amounts.view', 'presale_estimates.amounts.view']),
            'amount' => $amount,
            'rows' => $rows,
            'summary' => $this->sourceSummary('commercial_proposal', $proposal->id, $proposal->number, $proposal->title, $this->stringValue($proposal->status), $proposal->project_id, $proposal->contract_id),
        ];
    }

    private function tenderSource(int $organizationId, User $user, string $tenderId, bool $lock): array
    {
        $tender = Tender::query()
            ->forOrganization($organizationId)
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate())
            ->whereKey($tenderId)
            ->firstOrFail();

        if ($tender->commercial_proposal_id) {
            $source = $this->commercialProposalSource($organizationId, $user, (string) $tender->commercial_proposal_id, $lock);
            $source['tender'] = $tender;

            return $source;
        }

        $proposal = $this->proposalQuery($organizationId, $lock)
            ->where('tender_id', $tender->id)
            ->orderByDesc('updated_at')
            ->first();

        if ($proposal instanceof CommercialProposal) {
            $source = $this->commercialProposalSource($organizationId, $user, $proposal->id, $lock);
            $source['tender'] = $tender;

            return $source;
        }

        $amount = $this->tenderAmount($tender);
        $rows = $amount !== null
            ? [$this->fallbackTotalRow('tender', $tender->id, $tender->title, $amount, (string) ($tender->currency ?? 'RUB'))]
            : [];

        return [
            'source_type' => 'tender',
            'source_id' => $tender->id,
            'presale_estimate' => null,
            'presale_version' => null,
            'commercial_proposal' => null,
            'commercial_proposal_version' => null,
            'tender' => $tender,
            'crm_deal' => null,
            'project_id' => $tender->project_id,
            'contract_id' => $tender->contract_id,
            'currency' => $tender->currency ?? 'RUB',
            'amount_visible' => $this->canAny($user, $organizationId, ['tenders.amounts.view']),
            'amount' => $amount,
            'rows' => $rows,
            'summary' => $this->sourceSummary('tender', $tender->id, $tender->number, $tender->title, $tender->status, $tender->project_id, $tender->contract_id),
        ];
    }

    private function crmDealSource(int $organizationId, User $user, string $dealId, bool $lock): array
    {
        $deal = CrmDeal::query()
            ->forOrganization($organizationId)
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate())
            ->whereKey($dealId)
            ->firstOrFail();

        $proposal = $this->proposalQuery($organizationId, $lock)
            ->where('crm_deal_id', $deal->id)
            ->orderByDesc('updated_at')
            ->first();

        if ($proposal instanceof CommercialProposal) {
            $source = $this->commercialProposalSource($organizationId, $user, $proposal->id, $lock);
            $source['crm_deal'] = $deal;

            return $source;
        }

        $amount = $deal->amount !== null ? (float) $deal->amount : null;
        $rows = $amount !== null
            ? [$this->fallbackTotalRow('crm_deal', $deal->id, $deal->title, $amount, (string) ($deal->currency ?? 'RUB'))]
            : [];

        return [
            'source_type' => 'crm_deal',
            'source_id' => $deal->id,
            'presale_estimate' => null,
            'presale_version' => null,
            'commercial_proposal' => null,
            'commercial_proposal_version' => null,
            'tender' => null,
            'crm_deal' => $deal,
            'project_id' => $deal->project_id,
            'contract_id' => $deal->contract_id,
            'currency' => $deal->currency ?? 'RUB',
            'amount_visible' => $this->canAny($user, $organizationId, ['crm.amounts.view']),
            'amount' => $amount,
            'rows' => $rows,
            'summary' => $this->sourceSummary('crm_deal', $deal->id, null, $deal->title, $deal->status, $deal->project_id, $deal->contract_id),
        ];
    }

    private function presaleSource(
        int $organizationId,
        User $user,
        string $estimateId,
        ?CommercialProposal $proposal,
        ?Tender $tender,
        ?CrmDeal $deal,
        bool $lock
    ): array {
        $estimate = $this->presaleQuery($organizationId, $lock)
            ->whereKey($estimateId)
            ->firstOrFail();
        $version = $estimate->acceptedVersion ?? $estimate->currentVersion;
        $rows = $version instanceof PresaleEstimateVersion
            ? $this->presaleRows($estimate, $version)
            : [];
        $amount = $this->presaleAmount($estimate, $version, $rows);

        if ($rows === [] && $amount !== null) {
            $rows[] = $this->fallbackTotalRow('presale_estimate', $estimate->id, $estimate->title, $amount, (string) $estimate->currency);
        }

        return [
            'source_type' => 'presale_estimate',
            'source_id' => $estimate->id,
            'presale_estimate' => $estimate,
            'presale_version' => $version,
            'commercial_proposal' => $proposal,
            'commercial_proposal_version' => $proposal?->acceptedVersion ?? $proposal?->currentVersion,
            'tender' => $tender,
            'crm_deal' => $deal,
            'project_id' => $estimate->project_id ?? $proposal?->project_id ?? $tender?->project_id ?? $deal?->project_id,
            'contract_id' => $estimate->contract_id ?? $proposal?->contract_id ?? $tender?->contract_id ?? $deal?->contract_id,
            'currency' => $estimate->currency ?? $proposal?->currency ?? 'RUB',
            'amount_visible' => $this->canAny($user, $organizationId, ['presale_estimates.amounts.view', 'commercial_proposals.amounts.view']),
            'amount' => $amount,
            'rows' => $rows,
            'summary' => $this->sourceSummary('presale_estimate', $estimate->id, $estimate->number, $estimate->title, $estimate->status, $estimate->project_id, $estimate->contract_id),
        ];
    }

    private function resolveTarget(int $organizationId, User $user, array $data, array $source, bool $previewMode): array
    {
        $targetInput = is_array($data['target'] ?? null) ? $data['target'] : [];
        $blockers = [];
        $warnings = [];
        $projectId = $targetInput['project_id'] ?? $source['project_id'];
        $contractId = $targetInput['contract_id'] ?? $source['contract_id'];
        $project = $projectId ? Project::query()->where('organization_id', $organizationId)->whereKey((int) $projectId)->first() : null;
        $contract = $contractId ? Contract::query()->where('organization_id', $organizationId)->whereKey((int) $contractId)->first() : null;

        if (! $project instanceof Project) {
            $blockers[] = [
                'key' => 'project_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.project_required'),
            ];
        }

        if (! $contract instanceof Contract) {
            $blockers[] = [
                'key' => 'contract_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.contract_required'),
            ];
        }

        if ($project instanceof Project && $contract instanceof Contract && (int) $contract->project_id !== (int) $project->id) {
            $blockers[] = [
                'key' => 'contract_project_mismatch',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.contract_project_mismatch'),
            ];
        }

        $version = null;
        $createVersion = null;
        $period = null;
        $budgetKind = 'bdr';

        if (! empty($targetInput['budget_version_id'])) {
            $version = BudgetVersion::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', (string) $targetInput['budget_version_id'])
                ->with(['period', 'scenario'])
                ->first();

            if (! $version instanceof BudgetVersion) {
                $blockers[] = [
                    'key' => 'budget_version_not_found',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_not_found'),
                ];
            } else {
                $budgetKind = (string) $version->budget_kind;
                $period = $version->period;

                if ($version->status !== 'draft') {
                    $blockers[] = [
                        'key' => 'budget_version_not_editable',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_not_editable'),
                    ];
                }
            }
        } else {
            $createVersion = $this->normalizeCreateVersion($organizationId, $user, $targetInput['create_budget_version'] ?? null, $blockers);
            $budgetKind = $createVersion['budget_kind'] ?? 'bdr';
            $period = $createVersion['period_model'] ?? null;

            if ($createVersion === null) {
                $blockers[] = [
                    'key' => 'budget_version_required',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_required'),
                ];
            }
        }

        $defaultMonth = $this->defaultMonth($targetInput['default_month'] ?? null, $period);
        $this->appendMonthBlocker($defaultMonth, $period, $blockers);

        if (! $previewMode && $blockers !== []) {
            return [
                'project' => $project,
                'contract' => $contract,
                'budget_version' => $version,
                'create_budget_version' => $createVersion,
                'budget_kind' => $budgetKind,
                'default_month' => $defaultMonth,
                'warnings' => $warnings,
                'blockers' => $blockers,
            ];
        }

        return [
            'project' => $project,
            'contract' => $contract,
            'budget_version' => $version,
            'create_budget_version' => $createVersion,
            'budget_kind' => $budgetKind,
            'default_month' => $defaultMonth,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    private function previewRows(int $organizationId, array $source, array $data, array $target): array
    {
        $mapping = is_array($data['mapping'] ?? null) ? $data['mapping'] : [];
        $overrides = $this->mappingOverrides($mapping);
        $defaultArticleId = $mapping['default_budget_article_id'] ?? null;
        $defaultCenterId = $mapping['default_responsibility_center_id'] ?? null;
        $rows = [];

        foreach ($source['rows'] as $sourceRow) {
            $override = $overrides[$sourceRow['source_row_id']] ?? [];
            $lineType = (string) $sourceRow['line_type'];
            $excludedByDefault = in_array($lineType, self::EXCLUDED_LINE_TYPES, true);
            $included = array_key_exists('included', $override)
                ? (bool) $override['included']
                : ! $excludedByDefault;
            $article = $this->resolveArticle($organizationId, $sourceRow, $override, $defaultArticleId, (string) $target['budget_kind']);
            $center = $this->resolveCenter($organizationId, $sourceRow, $override, $defaultCenterId);
            $month = (string) ($override['month'] ?? $sourceRow['month'] ?? $target['default_month']);
            $amount = $source['amount_visible'] ? (float) ($sourceRow['amount'] ?? 0) : null;
            $planAmount = $source['amount_visible'] ? (float) ($override['plan_amount'] ?? $amount ?? 0) : null;
            $forecastAmount = $source['amount_visible'] ? (float) ($override['forecast_amount'] ?? $planAmount ?? 0) : null;
            $rowBlockers = [];
            $rowWarnings = [];

            if ($included) {
                if (! $article instanceof BudgetArticle) {
                    $rowBlockers[] = [
                        'key' => 'row_budget_article_required',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.row_budget_article_required', ['row' => $sourceRow['label']]),
                        'row_id' => $sourceRow['source_row_id'],
                    ];
                } elseif (! $article->is_active || ! $article->is_leaf || ! in_array($article->budget_kind, [(string) $target['budget_kind'], 'both'], true)) {
                    $rowBlockers[] = [
                        'key' => 'row_budget_article_invalid',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.row_budget_article_invalid', ['row' => $sourceRow['label']]),
                        'row_id' => $sourceRow['source_row_id'],
                    ];
                }

                if (! $center instanceof ResponsibilityCenter) {
                    $rowBlockers[] = [
                        'key' => 'row_responsibility_center_required',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.row_responsibility_center_required', ['row' => $sourceRow['label']]),
                        'row_id' => $sourceRow['source_row_id'],
                    ];
                } elseif (! $center->is_active) {
                    $rowBlockers[] = [
                        'key' => 'row_responsibility_center_invalid',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.row_responsibility_center_invalid', ['row' => $sourceRow['label']]),
                        'row_id' => $sourceRow['source_row_id'],
                    ];
                }

                if (! $this->monthInTargetPeriod($month, $target)) {
                    $rowBlockers[] = [
                        'key' => 'row_month_out_of_period',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.row_month_out_of_period', ['row' => $sourceRow['label']]),
                        'row_id' => $sourceRow['source_row_id'],
                    ];
                }
            }

            if ($excludedByDefault && ! array_key_exists('included', $override)) {
                $rowWarnings[] = [
                    'key' => 'row_excluded_by_type',
                    'label' => trans_message('presale_estimates.budget_transfer.warnings.row_excluded_by_type', ['row' => $sourceRow['label']]),
                    'row_id' => $sourceRow['source_row_id'],
                ];
            }

            $rows[] = [
                'source_row_id' => $sourceRow['source_row_id'],
                'source_type' => $sourceRow['source_type'],
                'source_label' => $sourceRow['label'],
                'description' => $sourceRow['description'],
                'line_type' => $lineType,
                'included' => $included,
                'mapping_status' => $included ? ($rowBlockers === [] ? 'mapped' : 'unmapped') : 'excluded',
                'amount_visible' => $source['amount_visible'],
                'amount' => $amount !== null ? $this->decimalString($amount) : null,
                'plan_amount' => $planAmount !== null ? $this->decimalString($planAmount) : null,
                'forecast_amount' => $forecastAmount !== null ? $this->decimalString($forecastAmount) : null,
                'currency' => $sourceRow['currency'] ?? $source['currency'],
                'month' => $month,
                'budget_article_id' => $article?->uuid,
                'budget_article_code' => $article?->code,
                'budget_article_name' => $article?->name,
                'responsibility_center_id' => $center?->uuid,
                'responsibility_center_code' => $center?->code,
                'responsibility_center_name' => $center?->name,
                'blockers' => $rowBlockers,
                'warnings' => $rowWarnings,
            ];
        }

        return $rows;
    }

    private function normalizedBudgetRows(
        int $organizationId,
        array $preview,
        BudgetVersion $version,
        string $operationId,
        int $userId
    ): array {
        $rows = [];

        foreach ($preview['rows'] as $row) {
            if ($row['included'] !== true) {
                continue;
            }

            $article = BudgetArticle::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', $row['budget_article_id'])
                ->first();
            $center = ResponsibilityCenter::query()
                ->where('organization_id', $organizationId)
                ->where('uuid', $row['responsibility_center_id'])
                ->first();

            if (! $article instanceof BudgetArticle || ! $center instanceof ResponsibilityCenter) {
                throw new PresaleEstimateBudgetTransferException(
                    trans_message('presale_estimates.budget_transfer.errors.validation_failed'),
                    409,
                    [[
                        'key' => 'mapping_invalid',
                        'label' => trans_message('presale_estimates.budget_transfer.blockers.mapping_invalid'),
                    ]]
                );
            }

            $rows[] = [
                'budget_article_id' => $article->id,
                'responsibility_center_id' => $center->id,
                'project_id' => $preview['target']['project']['id'],
                'contract_id' => $preview['target']['contract']['id'],
                'counterparty_id' => null,
                'currency' => $row['currency'] ?? $preview['amount']['currency'],
                'description' => $row['source_label'],
                'month' => $row['month'],
                'plan' => (float) $row['plan_amount'],
                'forecast' => (float) $row['forecast_amount'],
                'metadata' => [
                    'source' => [
                        'type' => $preview['source']['source_type'],
                        'id' => $preview['source']['id'],
                        'row_id' => $row['source_row_id'],
                        'row_type' => $row['source_type'],
                    ],
                    'operation_id' => $operationId,
                    'created_by_flow' => 'presale_budget_transfer',
                    'performed_by_user_id' => $userId,
                ],
                'budget_version_id' => $version->id,
            ];
        }

        return $rows;
    }

    private function createBudgetVersion(int $organizationId, User $user, ?array $input): BudgetVersion
    {
        if ($input === null) {
            throw new PresaleEstimateBudgetTransferException(
                trans_message('presale_estimates.budget_transfer.errors.validation_failed'),
                409,
                [[
                    'key' => 'budget_version_required',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_required'),
                ]]
            );
        }

        return $this->budgetVersionService->store($user, [
            'organization_id' => $organizationId,
            'budget_kind' => $input['budget_kind'],
            'budget_period_id' => $input['budget_period_id'],
            'scenario_id' => $input['scenario_id'],
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
        ]);
    }

    private function buildConvertResult(
        string $operationId,
        array $source,
        array $preview,
        BudgetVersion $version,
        int $linesCount
    ): array {
        return [
            'status' => 'converted',
            'idempotent_replay' => false,
            'operation_id' => $operationId,
            'source' => $source['summary'],
            'project' => $preview['target']['project'],
            'contract' => $preview['target']['contract'],
            'budget_version' => $this->budgetVersionSummary($version),
            'summary' => [
                'lines_created' => $linesCount,
                'plan_total' => $preview['summary']['plan_total'],
                'forecast_total' => $preview['summary']['forecast_total'],
                'currency' => $preview['summary']['currency'],
            ],
            'warnings' => $preview['warnings'],
            'next_actions' => [
                [
                    'key' => 'open_budget_version',
                    'label' => trans_message('presale_estimates.budget_transfer.next_actions.open_budget_version'),
                    'path' => '/budgeting/budgets/'.$version->uuid,
                ],
                [
                    'key' => 'open_project',
                    'label' => trans_message('presale_estimates.budget_transfer.next_actions.open_project'),
                    'path' => '/projects/'.$preview['target']['project']['id'],
                ],
            ],
        ];
    }

    private function normalizeCreateVersion(int $organizationId, User $user, mixed $input, array &$blockers): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $budgetKind = (string) ($input['budget_kind'] ?? 'bdr');
        $periodId = $input['budget_period_id'] ?? null;
        $scenarioId = $input['scenario_id'] ?? null;
        $name = trim((string) ($input['name'] ?? ''));
        $period = $periodId
            ? BudgetPeriod::query()->where('organization_id', $organizationId)->where('uuid', (string) $periodId)->first()
            : null;
        $scenario = $scenarioId
            ? BudgetScenario::query()->where('organization_id', $organizationId)->where('uuid', (string) $scenarioId)->where('is_active', true)->first()
            : null;

        if (! $period instanceof BudgetPeriod) {
            $blockers[] = [
                'key' => 'budget_period_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_period_required'),
            ];
        }

        if (! $scenario instanceof BudgetScenario) {
            $blockers[] = [
                'key' => 'budget_scenario_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_scenario_required'),
            ];
        }

        if ($name === '') {
            $blockers[] = [
                'key' => 'budget_version_name_required',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_name_required'),
            ];
        }

        return [
            'budget_period_id' => $period?->uuid ?? $periodId,
            'scenario_id' => $scenario?->uuid ?? $scenarioId,
            'budget_kind' => $budgetKind,
            'name' => $name,
            'description' => $input['description'] ?? null,
            'period_model' => $period,
            'scenario_model' => $scenario,
            'can_create' => $this->can($user, 'budgeting.budgets.create', $organizationId),
        ];
    }

    private function options(int $organizationId, string $budgetKind): array
    {
        return [
            'budget_versions' => BudgetVersion::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'draft')
                ->with(['period', 'scenario'])
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (BudgetVersion $version): array => $this->budgetVersionSummary($version))
                ->values()
                ->all(),
            'periods' => BudgetPeriod::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', ['open', 'reopened_for_adjustment'])
                ->orderByDesc('starts_at')
                ->limit(20)
                ->get()
                ->map(fn (BudgetPeriod $period): array => [
                    'id' => $period->uuid,
                    'code' => $period->code,
                    'name' => $period->name,
                    'starts_at' => $period->starts_at?->toDateString(),
                    'ends_at' => $period->ends_at?->toDateString(),
                    'status' => $period->status,
                ])
                ->all(),
            'scenarios' => BudgetScenario::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (BudgetScenario $scenario): array => [
                    'id' => $scenario->uuid,
                    'code' => $scenario->code,
                    'name' => $scenario->name,
                    'scenario_type' => $scenario->scenario_type,
                    'is_default' => $scenario->is_default,
                    'is_active' => $scenario->is_active,
                ])
                ->all(),
            'articles' => BudgetArticle::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where('is_leaf', true)
                ->whereIn('budget_kind', [$budgetKind, 'both'])
                ->orderBy('code')
                ->get()
                ->map(fn (BudgetArticle $article): array => [
                    'id' => $article->uuid,
                    'code' => $article->code,
                    'name' => $article->name,
                    'budget_kind' => $article->budget_kind,
                    'flow_direction' => $article->flow_direction,
                ])
                ->all(),
            'responsibility_centers' => ResponsibilityCenter::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(fn (ResponsibilityCenter $center): array => [
                    'id' => $center->uuid,
                    'code' => $center->code,
                    'name' => $center->name,
                    'center_type' => $center->center_type,
                    'is_active' => $center->is_active,
                ])
                ->all(),
        ];
    }

    private function targetToArray(array $target): array
    {
        return [
            'project' => $target['project'] instanceof Project ? $this->projectSummary($target['project']) : null,
            'contract' => $target['contract'] instanceof Contract ? $this->contractSummary($target['contract']) : null,
            'budget_version' => $target['budget_version'] instanceof BudgetVersion ? $this->budgetVersionSummary($target['budget_version']) : null,
            'create_budget_version' => $target['create_budget_version'] ? [
                'budget_period_id' => $target['create_budget_version']['budget_period_id'],
                'scenario_id' => $target['create_budget_version']['scenario_id'],
                'budget_kind' => $target['create_budget_version']['budget_kind'],
                'name' => $target['create_budget_version']['name'],
                'description' => $target['create_budget_version']['description'],
                'can_create' => $target['create_budget_version']['can_create'],
            ] : null,
            'default_month' => $target['default_month'],
        ];
    }

    private function sourceSelector(array $data): array
    {
        if (isset($data['source']) && is_array($data['source'])) {
            $type = (string) ($data['source']['source_type'] ?? '');
            $id = (string) ($data['source']['source_id'] ?? '');

            if (in_array($type, self::SOURCE_TYPES, true) && $id !== '') {
                return [$type, $id];
            }
        }

        foreach ([
            'presale_estimate_id' => 'presale_estimate',
            'commercial_proposal_id' => 'commercial_proposal',
            'tender_id' => 'tender',
            'crm_deal_id' => 'crm_deal',
        ] as $key => $type) {
            if (! empty($data[$key])) {
                return [$type, (string) $data[$key]];
            }
        }

        return [null, null];
    }

    private function presaleQuery(int $organizationId, bool $lock): Builder
    {
        return PresaleEstimate::query()
            ->forOrganization($organizationId)
            ->with([
                'acceptedVersion.lineItems.article',
                'acceptedVersion.lineItems.responsibilityCenter',
                'currentVersion.lineItems.article',
                'currentVersion.lineItems.responsibilityCenter',
            ])
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate());
    }

    private function proposalQuery(int $organizationId, bool $lock): Builder
    {
        return CommercialProposal::query()
            ->forOrganization($organizationId)
            ->with([
                'acceptedVersion.lineItems',
                'currentVersion.lineItems',
            ])
            ->when($lock, static fn (Builder $query) => $query->lockForUpdate());
    }

    private function presaleRows(PresaleEstimate $estimate, PresaleEstimateVersion $version): array
    {
        return $version->lineItems
            ->map(function (PresaleEstimateLineItem $item) use ($estimate): array {
                return [
                    'source_row_id' => $item->id,
                    'source_type' => 'presale_estimate_line_item',
                    'label' => $item->title,
                    'description' => $item->description,
                    'line_type' => $item->line_type ?: 'work',
                    'amount' => (float) $item->total_amount,
                    'currency' => $estimate->currency ?? 'RUB',
                    'month' => $item->planned_month?->format('Y-m'),
                    'budget_article_internal_id' => $item->budget_article_id,
                    'budget_article_id' => $item->article?->uuid,
                    'budget_article_code' => $item->article?->code ?? ($item->metadata['budget_article_code'] ?? null),
                    'responsibility_center_internal_id' => $item->responsibility_center_id,
                    'responsibility_center_id' => $item->responsibilityCenter?->uuid,
                    'responsibility_center_code' => $item->responsibilityCenter?->code ?? ($item->metadata['responsibility_center_code'] ?? null),
                    'metadata' => $item->metadata ?? [],
                ];
            })
            ->values()
            ->all();
    }

    private function commercialProposalRows(CommercialProposal $proposal, CommercialProposalVersion $version): array
    {
        return $version->lineItems
            ->map(function (CommercialProposalLineItem $item) use ($proposal): array {
                $metadata = $item->metadata ?? [];

                return [
                    'source_row_id' => $item->id,
                    'source_type' => 'commercial_proposal_line_item',
                    'label' => $item->title,
                    'description' => $item->description,
                    'line_type' => (string) ($metadata['line_type'] ?? 'work'),
                    'amount' => (float) $item->total_amount,
                    'currency' => $proposal->currency ?? 'RUB',
                    'month' => isset($metadata['month']) ? (string) $metadata['month'] : null,
                    'budget_article_internal_id' => null,
                    'budget_article_id' => $metadata['budget_article_id'] ?? null,
                    'budget_article_code' => $metadata['budget_article_code'] ?? null,
                    'responsibility_center_internal_id' => null,
                    'responsibility_center_id' => $metadata['responsibility_center_id'] ?? null,
                    'responsibility_center_code' => $metadata['responsibility_center_code'] ?? null,
                    'metadata' => $metadata,
                ];
            })
            ->values()
            ->all();
    }

    private function fallbackTotalRow(string $sourceType, string $sourceId, string $title, float $amount, string $currency): array
    {
        return [
            'source_row_id' => "{$sourceType}:{$sourceId}:total",
            'source_type' => "{$sourceType}_total",
            'label' => $title,
            'description' => null,
            'line_type' => 'total',
            'amount' => $amount,
            'currency' => $currency,
            'month' => null,
            'budget_article_internal_id' => null,
            'budget_article_id' => null,
            'budget_article_code' => null,
            'responsibility_center_internal_id' => null,
            'responsibility_center_id' => null,
            'responsibility_center_code' => null,
            'metadata' => [],
        ];
    }

    private function presaleAmount(PresaleEstimate $estimate, ?PresaleEstimateVersion $version, array $rows): ?float
    {
        $totals = is_array($version?->totals_snapshot) ? $version->totals_snapshot : [];
        $amount = $totals['total_amount'] ?? $totals['total'] ?? $estimate->total_amount;

        return $amount !== null ? (float) $amount : $this->rowsTotal($rows);
    }

    private function commercialProposalAmount(CommercialProposal $proposal, ?CommercialProposalVersion $version, array $rows): ?float
    {
        $totals = is_array($version?->totals_snapshot) ? $version->totals_snapshot : [];
        $amount = $totals['total_amount'] ?? $totals['total'] ?? $totals['grand_total'] ?? $proposal->total_amount;

        return $amount !== null ? (float) $amount : $this->rowsTotal($rows);
    }

    private function tenderAmount(Tender $tender): ?float
    {
        $amount = $tender->winner_amount
            ?? $tender->final_bid_amount
            ?? $tender->expected_bid_amount
            ?? $tender->initial_max_price;

        return $amount !== null ? (float) $amount : null;
    }

    private function rowsTotal(array $rows): ?float
    {
        if ($rows === []) {
            return null;
        }

        return array_reduce($rows, static fn (float $carry, array $row): float => $carry + (float) ($row['amount'] ?? 0), 0.0);
    }

    private function resolveArticle(int $organizationId, array $sourceRow, array $override, mixed $defaultId, string $budgetKind): ?BudgetArticle
    {
        $selector = $override['budget_article_id']
            ?? $sourceRow['budget_article_id']
            ?? $defaultId
            ?? null;

        $query = BudgetArticle::query()->where('organization_id', $organizationId);

        if ($selector) {
            return (clone $query)->where('uuid', (string) $selector)->first();
        }

        if (! empty($sourceRow['budget_article_internal_id'])) {
            return (clone $query)->whereKey((int) $sourceRow['budget_article_internal_id'])->first();
        }

        if (! empty($sourceRow['budget_article_code'])) {
            return (clone $query)
                ->where('code', (string) $sourceRow['budget_article_code'])
                ->whereIn('budget_kind', [$budgetKind, 'both'])
                ->first();
        }

        return null;
    }

    private function resolveCenter(int $organizationId, array $sourceRow, array $override, mixed $defaultId): ?ResponsibilityCenter
    {
        $selector = $override['responsibility_center_id']
            ?? $sourceRow['responsibility_center_id']
            ?? $defaultId
            ?? null;

        $query = ResponsibilityCenter::query()->where('organization_id', $organizationId);

        if ($selector) {
            return (clone $query)->where('uuid', (string) $selector)->first();
        }

        if (! empty($sourceRow['responsibility_center_internal_id'])) {
            return (clone $query)->whereKey((int) $sourceRow['responsibility_center_internal_id'])->first();
        }

        if (! empty($sourceRow['responsibility_center_code'])) {
            return (clone $query)->where('code', (string) $sourceRow['responsibility_center_code'])->first();
        }

        return null;
    }

    private function defaultMonth(mixed $input, ?BudgetPeriod $period): string
    {
        if (is_string($input) && preg_match('/^\d{4}-\d{2}$/', $input) === 1) {
            return $input;
        }

        if ($period instanceof BudgetPeriod && $period->starts_at !== null) {
            $startsAt = CarbonImmutable::parse((string) $period->starts_at)->startOfMonth();
            $now = CarbonImmutable::now()->startOfMonth();
            $endsAt = CarbonImmutable::parse((string) $period->ends_at)->startOfMonth();

            if ($now->betweenIncluded($startsAt, $endsAt)) {
                return $now->format('Y-m');
            }

            return $startsAt->format('Y-m');
        }

        return CarbonImmutable::now()->format('Y-m');
    }

    private function appendMonthBlocker(string $month, ?BudgetPeriod $period, array &$blockers): void
    {
        if ($period instanceof BudgetPeriod && ! $this->monthInPeriod($month, $period)) {
            $blockers[] = [
                'key' => 'default_month_out_of_period',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.default_month_out_of_period'),
            ];
        }
    }

    private function monthInTargetPeriod(string $month, array $target): bool
    {
        $period = $target['budget_version'] instanceof BudgetVersion
            ? $target['budget_version']->period
            : ($target['create_budget_version']['period_model'] ?? null);

        return ! $period instanceof BudgetPeriod || $this->monthInPeriod($month, $period);
    }

    private function monthInPeriod(string $month, BudgetPeriod $period): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return false;
        }

        $date = CarbonImmutable::parse("{$month}-01")->startOfMonth();
        $startsAt = CarbonImmutable::parse((string) $period->starts_at)->startOfMonth();
        $endsAt = CarbonImmutable::parse((string) $period->ends_at)->startOfMonth();

        return $date->betweenIncluded($startsAt, $endsAt);
    }

    private function mappingOverrides(array $mapping): array
    {
        $overrides = [];

        foreach (($mapping['rows'] ?? []) as $row) {
            if (is_array($row) && ! empty($row['source_row_id'])) {
                $overrides[(string) $row['source_row_id']] = $row;
            }
        }

        return $overrides;
    }

    private function appendAuthorizationBlockers(int $organizationId, User $user, array $data, array $target, array &$blockers): void
    {
        foreach (['budgeting.budgets.edit', 'budgeting.import.commit'] as $permission) {
            if (! $this->can($user, $permission, $organizationId)) {
                $blockers[] = [
                    'key' => 'permission_denied',
                    'label' => trans_message('presale_estimates.budget_transfer.blockers.permission_denied'),
                ];

                return;
            }
        }

        if ($target['budget_version'] === null && ! $this->can($user, 'budgeting.budgets.create', $organizationId)) {
            $blockers[] = [
                'key' => 'budget_version_create_permission_denied',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.budget_version_create_permission_denied'),
            ];
        }

        if (($data['mapping'] ?? []) !== [] && ! $this->can($user, 'presale_estimates.transfer.mapping.edit', $organizationId)) {
            $blockers[] = [
                'key' => 'mapping_permission_denied',
                'label' => trans_message('presale_estimates.budget_transfer.blockers.mapping_permission_denied'),
            ];
        }
    }

    private function sourceSummary(
        string $sourceType,
        string $id,
        ?string $number,
        string $title,
        ?string $status,
        mixed $projectId,
        mixed $contractId
    ): array {
        return [
            'id' => $id,
            'source_type' => $sourceType,
            'number' => $number,
            'title' => $title,
            'status' => $status,
            'project_id' => $projectId !== null ? (int) $projectId : null,
            'contract_id' => $contractId !== null ? (int) $contractId : null,
        ];
    }

    private function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'path' => "/projects/{$project->id}",
        ];
    }

    private function contractSummary(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'status' => $this->stringValue($contract->status),
            'project_id' => $contract->project_id,
            'path' => $contract->project_id ? "/projects/{$contract->project_id}/contracts/{$contract->id}" : "/contracts/{$contract->id}",
        ];
    }

    private function budgetVersionSummary(BudgetVersion $version): array
    {
        $version->loadMissing(['period', 'scenario']);

        return [
            'id' => $version->uuid,
            'name' => $version->name,
            'status' => $version->status,
            'budget_kind' => $version->budget_kind,
            'version_number' => $version->version_number,
            'budget_period' => $version->period instanceof BudgetPeriod ? [
                'id' => $version->period->uuid,
                'code' => $version->period->code,
                'name' => $version->period->name,
                'starts_at' => $version->period->starts_at?->toDateString(),
                'ends_at' => $version->period->ends_at?->toDateString(),
                'status' => $version->period->status,
            ] : null,
            'scenario' => $version->scenario instanceof BudgetScenario ? [
                'id' => $version->scenario->uuid,
                'code' => $version->scenario->code,
                'name' => $version->scenario->name,
            ] : null,
            'path' => '/budgeting/budgets/'.$version->uuid,
        ];
    }

    private function summary(array $rows, string $currency, bool $amountVisible): array
    {
        $includedRows = array_values(array_filter($rows, static fn (array $row): bool => $row['included'] === true));
        $unmappedRows = array_values(array_filter($rows, static fn (array $row): bool => $row['mapping_status'] === 'unmapped'));

        return [
            'rows_count' => count($rows),
            'included_rows_count' => count($includedRows),
            'unmapped_rows_count' => count($unmappedRows),
            'plan_total' => $amountVisible ? $this->decimalString(array_reduce($includedRows, static fn (float $carry, array $row): float => $carry + (float) ($row['plan_amount'] ?? 0), 0.0)) : null,
            'forecast_total' => $amountVisible ? $this->decimalString(array_reduce($includedRows, static fn (float $carry, array $row): float => $carry + (float) ($row['forecast_amount'] ?? 0), 0.0)) : null,
            'currency' => $currency,
        ];
    }

    private function uniqueMessages(array $messages): array
    {
        $seen = [];
        $unique = [];

        foreach ($messages as $message) {
            $key = implode('|', [
                (string) ($message['key'] ?? ''),
                (string) ($message['row_id'] ?? ''),
                (string) ($message['field'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $message;
        }

        return $unique;
    }

    private function payloadHash(array $data): string
    {
        unset($data['idempotency_key']);
        $this->ksortRecursive($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function previewHash(array $preview): string
    {
        unset($preview['preview_hash'], $preview['ready_to_convert']);
        $this->ksortRecursive($preview);

        return hash('sha256', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function ksortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }

        ksort($value);
    }

    private function canAny(User $user, int $organizationId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission, $organizationId)) {
                return true;
            }
        }

        return false;
    }

    private function can(User $user, string $permission, int $organizationId): bool
    {
        return $this->authorization->can($user, $permission, ['organization_id' => $organizationId]);
    }

    private function decimalString(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_object($value) && property_exists($value, 'value') ? (string) $value->value : (string) $value;
    }
}
