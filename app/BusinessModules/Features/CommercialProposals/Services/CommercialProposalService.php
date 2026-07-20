<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Services;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalApproval;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalFile;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalLineItem;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalSection;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalSentEvent;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalTemplate;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalTimelineEvent;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\DTOs\Contract\ContractDossierCreationResult;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CommercialProposalService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly CommercialProposalExportService $exportService,
        private readonly ContractDossierCreationService $contractDossiers,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $organizationId, bool $canViewAmounts): array
    {
        $query = CommercialProposal::query()
            ->forOrganization($organizationId);

        $counts = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (clone $query)->count(),
            'draft' => (int) ($counts[CommercialProposalStatus::DRAFT->value] ?? 0),
            'internal_review' => (int) ($counts[CommercialProposalStatus::INTERNAL_REVIEW->value] ?? 0),
            'approved' => (int) ($counts[CommercialProposalStatus::APPROVED->value] ?? 0),
            'sent' => (int) ($counts[CommercialProposalStatus::SENT->value] ?? 0),
            'customer_review' => (int) ($counts[CommercialProposalStatus::CUSTOMER_REVIEW->value] ?? 0),
            'accepted' => (int) ($counts[CommercialProposalStatus::ACCEPTED->value] ?? 0),
            'rejected' => (int) ($counts[CommercialProposalStatus::REJECTED->value] ?? 0),
            'expired' => (int) ($counts[CommercialProposalStatus::EXPIRED->value] ?? 0),
            'cancelled' => (int) ($counts[CommercialProposalStatus::CANCELLED->value] ?? 0),
            'amount_visible' => $canViewAmounts,
            'amounts_visible' => $canViewAmounts,
            'total_amount' => $canViewAmounts ? (float) ((clone $query)->sum('total_amount')) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function references(int $organizationId, bool $canViewAmounts): array
    {
        $crmDeals = CrmDeal::query()
            ->forOrganization($organizationId)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(static function (CrmDeal $deal) use ($canViewAmounts): array {
                $payload = [
                    'id' => $deal->id,
                    'title' => $deal->title,
                    'label' => $deal->title,
                    'status' => $deal->status,
                    'currency' => $deal->currency,
                ];

                if ($canViewAmounts) {
                    $payload['amount'] = $deal->amount;
                }

                return $payload;
            })
            ->values()
            ->all();

        $templates = $this->templates($organizationId)
            ->map(static fn (CommercialProposalTemplate $template): array => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'label' => $template->name,
                'is_default' => (bool) $template->is_default,
            ])
            ->values()
            ->all();

        return [
            'statuses' => collect(CommercialProposalStatus::cases())
                ->map(static fn (CommercialProposalStatus $status): array => [
                    'value' => $status->value,
                    'label' => trans_message($status->labelKey()),
                ])
                ->values()
                ->all(),
            'result_options' => [
                ['value' => CommercialProposalStatus::ACCEPTED->value, 'label' => trans_message('commercial_proposals.statuses.accepted')],
                ['value' => CommercialProposalStatus::REJECTED->value, 'label' => trans_message('commercial_proposals.statuses.rejected')],
                ['value' => CommercialProposalStatus::EXPIRED->value, 'label' => trans_message('commercial_proposals.statuses.expired')],
            ],
            'templates' => $templates,
            'crm_deals' => $crmDeals,
            'deals' => $crmDeals,
        ];
    }

    /**
     * @return Collection<int, CommercialProposalTemplate>
     */
    public function templates(int $organizationId): Collection
    {
        return CommercialProposalTemplate::query()
            ->where(static function ($query) use ($organizationId): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId);
            })
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function storeTemplate(int $organizationId, array $data): CommercialProposalTemplate
    {
        return DB::transaction(function () use ($organizationId, $data): CommercialProposalTemplate {
            if (($data['is_default'] ?? false) === true) {
                CommercialProposalTemplate::query()
                    ->where('organization_id', $organizationId)
                    ->update(['is_default' => false]);
            }

            $settings = $data['settings'] ?? [];

            return CommercialProposalTemplate::query()->create([
                'organization_id' => $organizationId,
                'code' => Str::slug((string) $data['code'], '_'),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'body_html' => $data['body_html'],
                'settings' => $settings,
                'version_hash' => hash('sha256', json_encode([
                    'body_html' => $data['body_html'],
                    'settings' => $settings,
                ], JSON_THROW_ON_ERROR)),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        });
    }

    public function paginate(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CommercialProposal::query()
            ->forOrganization($organizationId)
            ->with(['crmDeal', 'currentVersion.createdBy']);

        if (!empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (!empty($filters['status'])) {
            $query->withStatus((string) $filters['status']);
        }

        if (!empty($filters['customer'])) {
            $query->where('customer_name', 'ilike', '%' . trim((string) $filters['customer']) . '%');
        }

        foreach (['crm_deal_id', 'tender_id', 'project_id', 'contract_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    public function find(int $organizationId, string $proposalId, bool $loadDetail = false): CommercialProposal
    {
        $query = CommercialProposal::query()
            ->withTrashed()
            ->forOrganization($organizationId)
            ->with(['organization', 'crmDeal', 'currentVersion.createdBy', 'acceptedVersion.createdBy']);

        if ($loadDetail) {
            $query->with([
                'versions.createdBy',
                'files.uploadedBy',
                'approvals',
                'sentEvents',
                'timelineEvents.actor',
                'exports',
            ]);
        }

        $proposal = $query->findOrFail($proposalId);

        $this->appendDownloadUrls($proposal);
        $this->appendExportUrls($proposal);

        return $proposal;
    }

    public function create(int $organizationId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId): CommercialProposal {
            $this->validateCrmDeal($organizationId, $data['crm_deal_id'] ?? null);

            $proposal = CommercialProposal::query()->create([
                'organization_id' => $organizationId,
                'crm_deal_id' => $data['crm_deal_id'] ?? null,
                'tender_id' => $data['tender_id'] ?? null,
                'presale_estimate_id' => $data['presale_estimate_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'number' => $data['number'] ?? $this->nextProposalNumber($organizationId),
                'title' => $data['title'],
                'status' => CommercialProposalStatus::DRAFT,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'currency' => $data['currency'] ?? 'RUB',
                'valid_until' => $data['valid_until'] ?? null,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
                'metadata' => $data['metadata'] ?? [],
            ]);

            $content = $this->buildVersionContent($proposal, $data);
            $version = $this->storeVersion($proposal, 1, $content, $actorUserId, 'draft');
            $this->replaceVersionRows($proposal, $version, $content['sections']);
            $this->syncProposalAmounts($proposal, $content['totals']);
            $proposal->forceFill(['current_version_id' => $version->id])->save();

            $this->recordTimeline(
                $proposal,
                'proposal.created',
                trans_message('commercial_proposals.messages.created'),
                null,
                CommercialProposalStatus::DRAFT,
                $actorUserId,
                $version
            );

            return $this->find($organizationId, $proposal->id, true);
        });
    }

    public function updateDraft(int $organizationId, string $proposalId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $this->assertStatus($proposal, [CommercialProposalStatus::DRAFT], 'draft_only');
            $this->validateCrmDeal($organizationId, $data['crm_deal_id'] ?? null);

            $proposal->fill(array_filter([
                'crm_deal_id' => array_key_exists('crm_deal_id', $data) ? $data['crm_deal_id'] : null,
                'tender_id' => array_key_exists('tender_id', $data) ? $data['tender_id'] : null,
                'presale_estimate_id' => array_key_exists('presale_estimate_id', $data) ? $data['presale_estimate_id'] : null,
                'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : null,
                'contract_id' => array_key_exists('contract_id', $data) ? $data['contract_id'] : null,
                'title' => $data['title'] ?? null,
                'customer_name' => array_key_exists('customer_name', $data) ? $data['customer_name'] : null,
                'customer_email' => array_key_exists('customer_email', $data) ? $data['customer_email'] : null,
                'customer_phone' => array_key_exists('customer_phone', $data) ? $data['customer_phone'] : null,
                'currency' => $data['currency'] ?? null,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : null,
                'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : null,
                'updated_by_user_id' => $actorUserId,
            ], static fn ($value, string $key): bool => $value !== null || in_array($key, [
                'crm_deal_id',
                'tender_id',
                'presale_estimate_id',
                'project_id',
                'contract_id',
                'customer_name',
                'customer_email',
                'customer_phone',
                'valid_until',
            ], true), ARRAY_FILTER_USE_BOTH));
            $proposal->save();

            $currentVersion = $this->currentVersion($proposal);
            $content = $this->buildVersionContent($proposal->refresh(), $data, $currentVersion);
            $currentVersion->forceFill([
                'title' => $content['title'],
                'sections_snapshot' => $content['sections'],
                'source_links_snapshot' => $content['source_links'],
                'terms_snapshot' => $content['terms'],
                'totals_snapshot' => $content['totals'],
                'content_hash' => $content['content_hash'],
                'template_version_hash' => $content['template_version_hash'],
            ])->save();
            $this->replaceVersionRows($proposal, $currentVersion, $content['sections']);
            $this->syncProposalAmounts($proposal, $content['totals']);

            $this->recordTimeline(
                $proposal,
                'proposal.updated',
                trans_message('commercial_proposals.messages.updated'),
                CommercialProposalStatus::DRAFT,
                CommercialProposalStatus::DRAFT,
                $actorUserId,
                $currentVersion
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function archive(int $organizationId, string $proposalId, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $fromStatus = $this->statusOf($proposal);
            $toStatus = $fromStatus->isFinal() ? $fromStatus : CommercialProposalStatus::CANCELLED;

            if ($proposal->deleted_at === null) {
                $proposal->forceFill([
                    'status' => $toStatus,
                    'archived_at' => now(),
                    'updated_by_user_id' => $actorUserId,
                ])->save();

                $this->recordTimeline(
                    $proposal,
                    'proposal.archived',
                    trans_message('commercial_proposals.messages.archived'),
                    $fromStatus,
                    $toStatus,
                    $actorUserId,
                    $proposal->currentVersion
                );

                $proposal->delete();
            }

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function createVersion(int $organizationId, string $proposalId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $fromStatus = $this->statusOf($proposal);
            $currentVersion = $this->currentVersion($proposal);
            $content = $this->buildVersionContent($proposal, $data, $currentVersion);
            $nextVersionNumber = ((int) $proposal->versions()->max('version_number')) + 1;
            $version = $this->storeVersion($proposal, $nextVersionNumber, $content, $actorUserId, 'draft');
            $this->replaceVersionRows($proposal, $version, $content['sections']);
            $this->syncProposalAmounts($proposal, $content['totals']);

            $proposal->forceFill([
                'current_version_id' => $version->id,
                'title' => $content['title'],
                'status' => CommercialProposalStatus::DRAFT,
                'updated_by_user_id' => $actorUserId,
            ])->save();

            $this->recordTimeline(
                $proposal,
                'proposal.version_created',
                trans_message('commercial_proposals.messages.version_created'),
                $fromStatus,
                CommercialProposalStatus::DRAFT,
                $actorUserId,
                $version,
                ['version_number' => $nextVersionNumber]
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function requestApproval(int $organizationId, string $proposalId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $version = $this->currentVersion($proposal);
            $blockers = $this->approvalBlockers($proposal, $version);

            if ($blockers !== []) {
                throw new CommercialProposalWorkflowException($blockers, $this->firstBlockerMessage($blockers));
            }

            CommercialProposalApproval::query()->create([
                'organization_id' => $organizationId,
                'commercial_proposal_id' => $proposal->id,
                'commercial_proposal_version_id' => $version->id,
                'requested_by_user_id' => $actorUserId,
                'status' => 'pending',
                'comment' => $data['comment'] ?? null,
                'requested_at' => now(),
            ]);

            $proposal->forceFill([
                'status' => CommercialProposalStatus::INTERNAL_REVIEW,
                'updated_by_user_id' => $actorUserId,
            ])->save();
            $version->forceFill([
                'status' => CommercialProposalStatus::INTERNAL_REVIEW->value,
                'submitted_at' => now(),
            ])->save();

            $this->recordTimeline(
                $proposal,
                'proposal.approval_requested',
                trans_message('commercial_proposals.messages.approval_requested'),
                CommercialProposalStatus::DRAFT,
                CommercialProposalStatus::INTERNAL_REVIEW,
                $actorUserId,
                $version,
                ['comment' => $data['comment'] ?? null]
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function decideApproval(int $organizationId, string $proposalId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $this->assertStatus($proposal, [CommercialProposalStatus::INTERNAL_REVIEW], 'approval_decision_status');
            $version = $this->currentVersion($proposal);
            $approval = CommercialProposalApproval::query()
                ->where('organization_id', $organizationId)
                ->where('commercial_proposal_id', $proposal->id)
                ->where('commercial_proposal_version_id', $version->id)
                ->where('status', 'pending')
                ->first();

            if (!$approval instanceof CommercialProposalApproval) {
                $this->block('approval_missing');
            }

            $approved = $data['decision'] === 'approved';
            $toStatus = $approved ? CommercialProposalStatus::APPROVED : CommercialProposalStatus::DRAFT;

            $approval->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'comment' => $data['comment'] ?? $approval->comment,
                'decided_by_user_id' => $actorUserId,
                'decided_at' => now(),
            ])->save();
            $proposal->forceFill([
                'status' => $toStatus,
                'updated_by_user_id' => $actorUserId,
            ])->save();
            $version->forceFill([
                'status' => $toStatus->value,
                'approved_at' => $approved ? now() : null,
                'locked_at' => $approved ? now() : null,
            ])->save();

            $this->recordTimeline(
                $proposal,
                $approved ? 'proposal.approved' : 'proposal.rejected_by_reviewer',
                trans_message('commercial_proposals.messages.approval_decided'),
                CommercialProposalStatus::INTERNAL_REVIEW,
                $toStatus,
                $actorUserId,
                $version,
                ['decision' => $data['decision'], 'comment' => $data['comment'] ?? null]
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function send(
        int $organizationId,
        string $proposalId,
        array $data,
        ?int $actorUserId,
        bool $canViewAmounts
    ): CommercialProposal {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId, $canViewAmounts): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $this->assertStatus($proposal, [CommercialProposalStatus::APPROVED], 'send_only_after_approval');
            $version = $this->currentVersion($proposal);

            if ($version->status !== CommercialProposalStatus::APPROVED->value) {
                $this->block('send_only_after_approval');
            }

            $export = $this->exportService->export($proposal, [
                'version_id' => $version->id,
                'format' => 'pdf',
            ], $canViewAmounts, $actorUserId);

            CommercialProposalSentEvent::query()->create([
                'organization_id' => $organizationId,
                'commercial_proposal_id' => $proposal->id,
                'commercial_proposal_version_id' => $version->id,
                'sent_by_user_id' => $actorUserId,
                'channel' => $data['channel'],
                'recipient' => $data['recipient'],
                'subject' => $data['subject'] ?? null,
                'message' => $data['message'] ?? null,
                'payload' => array_merge($data['payload'] ?? [], [
                    'export_id' => $export->id,
                ]),
                'sent_at' => now(),
            ]);

            $proposal->forceFill([
                'status' => CommercialProposalStatus::SENT,
                'sent_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ])->save();
            $version->forceFill([
                'status' => CommercialProposalStatus::SENT->value,
                'sent_at' => now(),
            ])->save();

            $this->recordTimeline(
                $proposal,
                'proposal.sent',
                trans_message('commercial_proposals.messages.sent'),
                CommercialProposalStatus::APPROVED,
                CommercialProposalStatus::SENT,
                $actorUserId,
                $version,
                [
                    'channel' => $data['channel'],
                    'recipient' => $data['recipient'],
                    'export_id' => $export->id,
                ]
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function recordResult(int $organizationId, string $proposalId, array $data, ?int $actorUserId): CommercialProposal
    {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId): CommercialProposal {
            $proposal = $this->find($organizationId, $proposalId, true);
            $this->assertStatus($proposal, [
                CommercialProposalStatus::SENT,
                CommercialProposalStatus::CUSTOMER_REVIEW,
            ], 'result_only_after_send');

            $fromStatus = $this->statusOf($proposal);
            $toStatus = CommercialProposalStatus::from((string) $data['result']);
            $version = $this->currentVersion($proposal);
            $metadata = $proposal->metadata ?? [];
            $metadata['customer_result_comment'] = $data['comment'] ?? null;

            $proposal->forceFill([
                'status' => $toStatus,
                'customer_decision_at' => $data['decided_at'] ?? now(),
                'accepted_version_id' => $toStatus === CommercialProposalStatus::ACCEPTED
                    ? $version->id
                    : $proposal->accepted_version_id,
                'metadata' => $metadata,
                'updated_by_user_id' => $actorUserId,
            ])->save();
            $version->forceFill([
                'status' => $toStatus->value,
                'customer_decision_at' => $data['decided_at'] ?? now(),
            ])->save();

            $this->recordTimeline(
                $proposal,
                "proposal.result.{$toStatus->value}",
                trans_message('commercial_proposals.messages.result_recorded'),
                $fromStatus,
                $toStatus,
                $actorUserId,
                $version,
                ['comment' => $data['comment'] ?? null]
            );

            return $this->find($organizationId, $proposalId, true);
        });
    }

    public function createContract(
        int $organizationId,
        string $proposalId,
        User $actor,
        ContractDossierCreationInput $input,
    ): ContractDossierCreationResult {
        return DB::transaction(function () use ($organizationId, $proposalId, $actor, $input): ContractDossierCreationResult {
            $proposal = CommercialProposal::query()
                ->whereKey($proposalId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($proposal->status !== CommercialProposalStatus::ACCEPTED
                || $proposal->project_id === null
                || (int) $proposal->project_id !== (int) $input->contract->project_id) {
                $this->block('contract_only_from_accepted_proposal');
            }
            if (! $this->authorization->can($actor, 'contracts.create', [
                'organization_id' => $organizationId,
                'project_id' => (int) $proposal->project_id,
            ])) {
                $this->block('contract_create_forbidden');
            }
            if ($proposal->contract_id !== null) {
                $contract = Contract::query()
                    ->whereKey($proposal->contract_id)
                    ->where('organization_id', $organizationId)
                    ->first();
                if ($contract === null || $contract->legal_archive_document_id === null) {
                    $this->block('contract_dossier_creation_incomplete');
                }
                $document = $contract->legalArchiveDocument;
                if (! $document instanceof LegalArchiveDocument
                    || (int) $document->organization_id !== $organizationId) {
                    $this->block('contract_dossier_creation_incomplete');
                }

                return new ContractDossierCreationResult($contract, $document, true);
            }
            $result = $this->contractDossiers->create($organizationId, $actor, $input);
            $proposal->update(['contract_id' => $result->contract->id, 'updated_by_user_id' => $actor->id]);

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $organizationId, string $proposalId, ?string $versionId, bool $canViewAmounts): array
    {
        $proposal = $this->find($organizationId, $proposalId, true);

        return $this->exportService->preview($proposal, $versionId, $canViewAmounts);
    }

    /**
     * @return array{proposal: CommercialProposal, version: CommercialProposalVersion, export: CommercialProposalExport}
     */
    public function export(
        int $organizationId,
        string $proposalId,
        array $data,
        ?int $actorUserId,
        bool $canViewAmounts
    ): array {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $actorUserId, $canViewAmounts): array {
            $proposal = $this->find($organizationId, $proposalId, true);
            $version = $this->resolveVersion($proposal, $data['version_id'] ?? null);
            $export = $this->exportService->export($proposal, $data + [
                'version_id' => $version->id,
            ], $canViewAmounts, $actorUserId);
            $this->appendExportUrl($export, $proposal);

            $this->recordTimeline(
                $proposal,
                'proposal.export_created',
                trans_message('commercial_proposals.messages.export_ready'),
                $this->statusOf($proposal),
                $this->statusOf($proposal),
                $actorUserId,
                $version,
                ['export_id' => $export->id, 'format' => $export->format]
            );

            return [
                'proposal' => $this->find($organizationId, $proposalId, true),
                'version' => $version->fresh(),
                'export' => $export,
            ];
        });
    }

    public function exportStatus(int $organizationId, string $proposalId, string $exportId): CommercialProposalExport
    {
        $proposal = $this->find($organizationId, $proposalId);
        $export = CommercialProposalExport::query()
            ->where('organization_id', $organizationId)
            ->where('commercial_proposal_id', $proposal->id)
            ->findOrFail($exportId);

        $this->appendExportUrl($export, $proposal);

        return $export;
    }

    public function uploadFile(
        int $organizationId,
        string $proposalId,
        array $data,
        UploadedFile $file,
        ?int $actorUserId
    ): CommercialProposalFile {
        return DB::transaction(function () use ($organizationId, $proposalId, $data, $file, $actorUserId): CommercialProposalFile {
            $proposal = $this->find($organizationId, $proposalId, true);
            $version = isset($data['version_id']) ? $this->resolveVersion($proposal, (string) $data['version_id']) : null;
            $category = (string) ($data['category'] ?? 'attachment');
            $directory = $version instanceof CommercialProposalVersion
                ? "commercial-proposals/{$proposal->id}/versions/{$version->id}/{$category}"
                : "commercial-proposals/{$proposal->id}/files/{$category}";
            $path = $this->fileService->upload(
                $file,
                $directory,
                null,
                'private',
                $proposal->organization
            );

            if ($path === false) {
                throw ValidationException::withMessages([
                    'file' => [trans_message('commercial_proposals.blockers.file_upload_failed')],
                ]);
            }

            $storedFile = CommercialProposalFile::query()->create([
                'organization_id' => $organizationId,
                'commercial_proposal_id' => $proposal->id,
                'commercial_proposal_version_id' => $version?->id,
                'uploaded_by_user_id' => $actorUserId,
                'category' => $category,
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'metadata' => $data['metadata'] ?? [],
            ]);
            $storedFile->setAttribute(
                'download_url',
                $this->fileService->temporaryUrl($path, 60, $proposal->organization)
            );

            $this->recordTimeline(
                $proposal,
                'proposal.file_uploaded',
                trans_message('commercial_proposals.messages.file_uploaded'),
                $this->statusOf($proposal),
                $this->statusOf($proposal),
                $actorUserId,
                $version,
                ['file_id' => $storedFile->id, 'name' => $storedFile->original_name]
            );

            return $storedFile->loadMissing('uploadedBy');
        });
    }

    public function deleteFile(int $organizationId, string $proposalId, string $fileId, ?int $actorUserId): void
    {
        DB::transaction(function () use ($organizationId, $proposalId, $fileId, $actorUserId): void {
            $proposal = $this->find($organizationId, $proposalId, true);
            $file = CommercialProposalFile::query()
                ->where('organization_id', $organizationId)
                ->where('commercial_proposal_id', $proposal->id)
                ->findOrFail($fileId);

            $this->recordTimeline(
                $proposal,
                'proposal.file_deleted',
                trans_message('commercial_proposals.messages.file_deleted'),
                $this->statusOf($proposal),
                $this->statusOf($proposal),
                $actorUserId,
                $file->version,
                ['file_id' => $file->id, 'name' => $file->original_name]
            );

            if (!$this->fileService->delete($file->storage_path, $proposal->organization)) {
                $this->block('file_delete_failed');
            }

            $file->delete();
        });
    }

    private function validateCrmDeal(int $organizationId, mixed $dealId): void
    {
        if ($dealId === null || $dealId === '') {
            return;
        }

        $exists = CrmDeal::query()
            ->forOrganization($organizationId)
            ->whereKey($dealId)
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'crm_deal_id' => [trans_message('commercial_proposals.errors.deal_not_found')],
            ]);
        }
    }

    private function currentVersion(CommercialProposal $proposal): CommercialProposalVersion
    {
        $version = $proposal->relationLoaded('currentVersion')
            ? $proposal->currentVersion
            : $proposal->currentVersion()->first();

        if (!$version instanceof CommercialProposalVersion) {
            $this->block('version_missing');
        }

        return $version;
    }

    private function resolveVersion(CommercialProposal $proposal, mixed $versionId): CommercialProposalVersion
    {
        if ($versionId === null || $versionId === '') {
            return $this->currentVersion($proposal);
        }

        $version = CommercialProposalVersion::query()
            ->where('organization_id', $proposal->organization_id)
            ->where('commercial_proposal_id', $proposal->id)
            ->find($versionId);

        if (!$version instanceof CommercialProposalVersion) {
            throw ValidationException::withMessages([
                'version_id' => [trans_message('commercial_proposals.errors.version_not_found')],
            ]);
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVersionContent(
        CommercialProposal $proposal,
        array $data,
        ?CommercialProposalVersion $fallbackVersion = null
    ): array {
        $template = $this->resolveTemplateData($proposal->organization_id, $data['template_id'] ?? null);
        if (
            $template !== null
            && !array_key_exists('sections', $data)
            && !array_key_exists('line_items', $data)
            && $fallbackVersion === null
        ) {
            $data['sections'] = [[
                'title' => trans_message('commercial_proposals.defaults.section_title'),
                'body' => $template['body_html'],
                'sort_order' => 0,
            ]];
        }

        $sections = array_key_exists('sections', $data) || array_key_exists('line_items', $data)
            ? $this->normalizeSections($data)
            : ($fallbackVersion?->sections_snapshot ?? $this->normalizeSections($data));
        if (array_key_exists('sections', $data) && !array_key_exists('line_items', $data) && $fallbackVersion !== null) {
            $fallbackSections = is_array($fallbackVersion->sections_snapshot) ? $fallbackVersion->sections_snapshot : [];
            $sections = array_map(static function (array $section, int $index) use ($fallbackSections): array {
                $fallbackSection = $fallbackSections[$index] ?? [];
                $section['line_items'] = is_array($fallbackSection) ? ($fallbackSection['line_items'] ?? []) : [];

                return $section;
            }, $sections, array_keys($sections));
        }
        $terms = array_key_exists('terms', $data)
            ? ($data['terms'] ?? [])
            : ($fallbackVersion?->terms_snapshot ?? []);
        $sourceLinks = array_key_exists('source_links', $data)
            ? ($data['source_links'] ?? [])
            : ($fallbackVersion?->source_links_snapshot ?? []);
        $sourceLinks = array_merge($sourceLinks, [
            'crm_deal_id' => $proposal->crm_deal_id,
            'tender_id' => $proposal->tender_id,
            'presale_estimate_id' => $proposal->presale_estimate_id,
            'project_id' => $proposal->project_id,
            'contract_id' => $proposal->contract_id,
        ]);
        $totals = $this->calculateTotals($sections, $data['currency'] ?? $proposal->currency ?? 'RUB');
        $title = (string) ($data['title'] ?? $proposal->title);
        $templateHash = $template['version_hash'] ?? null;
        $contentHash = hash('sha256', json_encode([
            'title' => $title,
            'sections' => $sections,
            'terms' => $terms,
            'source_links' => $sourceLinks,
            'totals' => $totals,
        ], JSON_THROW_ON_ERROR));

        return [
            'title' => $title,
            'sections' => $sections,
            'terms' => $terms,
            'source_links' => $sourceLinks,
            'totals' => $totals,
            'content_hash' => $contentHash,
            'template_version_hash' => $templateHash,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSections(array $data): array
    {
        $inputSections = $data['sections'] ?? [];
        $lineItems = $data['line_items'] ?? [];

        if ($inputSections === [] && $lineItems !== []) {
            $inputSections = [[
                'title' => trans_message('commercial_proposals.defaults.section_title'),
                'body' => null,
                'sort_order' => 0,
            ]];
        }

        if ($inputSections === []) {
            $inputSections = [[
                'title' => trans_message('commercial_proposals.defaults.section_title'),
                'body' => null,
                'sort_order' => 0,
            ]];
        }

        $sections = [];
        foreach (array_values($inputSections) as $index => $section) {
            $sections[$index] = [
                'title' => (string) ($section['title'] ?? trans_message('commercial_proposals.defaults.section_title')),
                'body' => $section['body'] ?? null,
                'sort_order' => (int) ($section['sort_order'] ?? $index),
                'metadata' => $section['metadata'] ?? [],
                'line_items' => [],
            ];
        }

        foreach (array_values($lineItems) as $index => $item) {
            $sectionIndex = (int) ($item['section_index'] ?? 0);
            if (!array_key_exists($sectionIndex, $sections)) {
                $sectionIndex = 0;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $vatRate = array_key_exists('vat_rate', $item) && $item['vat_rate'] !== null ? (float) $item['vat_rate'] : null;
            $subtotal = max(($quantity * $unitPrice) - $discount, 0);
            $vatAmount = $vatRate === null ? 0.0 : round($subtotal * $vatRate / 100, 2);

            $sections[$sectionIndex]['line_items'][] = [
                'title' => (string) ($item['title'] ?? ''),
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'vat_rate' => $vatRate,
                'subtotal_amount' => round($subtotal, 2),
                'total_amount' => round($subtotal + $vatAmount, 2),
                'sort_order' => (int) ($item['sort_order'] ?? $index),
                'metadata' => $item['metadata'] ?? [],
            ];
        }

        return array_values($sections);
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function calculateTotals(array $sections, string $currency): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $vat = 0.0;
        $total = 0.0;

        foreach ($sections as $section) {
            foreach (($section['line_items'] ?? []) as $item) {
                $itemSubtotal = (float) ($item['subtotal_amount'] ?? 0);
                $itemTotal = (float) ($item['total_amount'] ?? 0);

                $subtotal += $itemSubtotal;
                $discount += (float) ($item['discount_amount'] ?? 0);
                $vat += max($itemTotal - $itemSubtotal, 0);
                $total += $itemTotal;
            }
        }

        return [
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'vat_amount' => round($vat, 2),
            'total_amount' => round($total, 2),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{version_hash:string,body_html:string}|null
     */
    private function resolveTemplateData(int $organizationId, mixed $templateId): ?array
    {
        if ($templateId === null || $templateId === '') {
            return null;
        }

        $template = CommercialProposalTemplate::query()
            ->where(static function ($query) use ($organizationId): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId);
            })
            ->whereKey($templateId)
            ->first();

        if (!$template instanceof CommercialProposalTemplate) {
            throw ValidationException::withMessages([
                'template_id' => [trans_message('commercial_proposals.errors.template_not_found')],
            ]);
        }

        return [
            'version_hash' => (string) $template->version_hash,
            'body_html' => (string) $template->body_html,
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function storeVersion(
        CommercialProposal $proposal,
        int $versionNumber,
        array $content,
        ?int $actorUserId,
        string $status
    ): CommercialProposalVersion {
        return CommercialProposalVersion::query()->create([
            'organization_id' => $proposal->organization_id,
            'commercial_proposal_id' => $proposal->id,
            'version_number' => $versionNumber,
            'status' => $status,
            'title' => $content['title'],
            'sections_snapshot' => $content['sections'],
            'source_links_snapshot' => $content['source_links'],
            'terms_snapshot' => $content['terms'],
            'totals_snapshot' => $content['totals'],
            'diff_summary' => [],
            'content_hash' => $content['content_hash'],
            'template_version_hash' => $content['template_version_hash'],
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function replaceVersionRows(CommercialProposal $proposal, CommercialProposalVersion $version, array $sections): void
    {
        $version->lineItems()->delete();
        $version->sections()->delete();

        foreach ($sections as $sectionIndex => $sectionData) {
            $section = CommercialProposalSection::query()->create([
                'organization_id' => $proposal->organization_id,
                'commercial_proposal_id' => $proposal->id,
                'commercial_proposal_version_id' => $version->id,
                'title' => $sectionData['title'],
                'body' => $sectionData['body'] ?? null,
                'sort_order' => (int) ($sectionData['sort_order'] ?? $sectionIndex),
                'metadata' => $sectionData['metadata'] ?? [],
            ]);

            foreach (($sectionData['line_items'] ?? []) as $itemIndex => $itemData) {
                CommercialProposalLineItem::query()->create([
                    'organization_id' => $proposal->organization_id,
                    'commercial_proposal_id' => $proposal->id,
                    'commercial_proposal_version_id' => $version->id,
                    'commercial_proposal_section_id' => $section->id,
                    'title' => $itemData['title'],
                    'description' => $itemData['description'] ?? null,
                    'unit' => $itemData['unit'] ?? null,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'unit_price' => $itemData['unit_price'] ?? 0,
                    'discount_amount' => $itemData['discount_amount'] ?? 0,
                    'vat_rate' => $itemData['vat_rate'] ?? null,
                    'subtotal_amount' => $itemData['subtotal_amount'] ?? 0,
                    'total_amount' => $itemData['total_amount'] ?? 0,
                    'sort_order' => (int) ($itemData['sort_order'] ?? $itemIndex),
                    'metadata' => $itemData['metadata'] ?? [],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function syncProposalAmounts(CommercialProposal $proposal, array $totals): void
    {
        $proposal->forceFill([
            'subtotal_amount' => $totals['subtotal_amount'] ?? 0,
            'discount_amount' => $totals['discount_amount'] ?? 0,
            'vat_amount' => $totals['vat_amount'] ?? 0,
            'total_amount' => $totals['total_amount'] ?? 0,
            'currency' => $totals['currency'] ?? $proposal->currency,
        ])->save();
    }

    /**
     * @return list<array{code:string,message:string}>
     */
    private function approvalBlockers(CommercialProposal $proposal, CommercialProposalVersion $version): array
    {
        $blockers = [];

        if ($this->statusOf($proposal) !== CommercialProposalStatus::DRAFT) {
            $blockers[] = $this->blocker('approval_only_from_draft');
        }

        if ($proposal->pendingApproval instanceof CommercialProposalApproval) {
            $blockers[] = $this->blocker('approval_exists');
        }

        if ($this->versionIsEmpty($version)) {
            $blockers[] = $this->blocker('content_required');
        }

        return $blockers;
    }

    private function versionIsEmpty(CommercialProposalVersion $version): bool
    {
        $sections = $version->sections_snapshot ?? [];

        foreach ($sections as $section) {
            if (trim((string) ($section['body'] ?? '')) !== '') {
                return false;
            }

            if (($section['line_items'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, CommercialProposalStatus> $allowedStatuses
     */
    private function assertStatus(CommercialProposal $proposal, array $allowedStatuses, string $blockerKey): void
    {
        $status = $this->statusOf($proposal);

        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $this->block($blockerKey);
    }

    private function statusOf(CommercialProposal $proposal): CommercialProposalStatus
    {
        return $proposal->status instanceof CommercialProposalStatus
            ? $proposal->status
            : CommercialProposalStatus::from((string) $proposal->status);
    }

    private function nextProposalNumber(int $organizationId): string
    {
        $prefix = 'KP-' . now()->format('Ym') . '-';
        $count = CommercialProposal::query()
            ->withTrashed()
            ->forOrganization($organizationId)
            ->where('number', 'like', "{$prefix}%")
            ->count() + 1;

        return $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function recordTimeline(
        CommercialProposal $proposal,
        string $eventType,
        string $message,
        ?CommercialProposalStatus $fromStatus,
        CommercialProposalStatus $toStatus,
        ?int $actorUserId,
        ?CommercialProposalVersion $version = null,
        array $payload = []
    ): void {
        CommercialProposalTimelineEvent::query()->create([
            'organization_id' => $proposal->organization_id,
            'commercial_proposal_id' => $proposal->id,
            'commercial_proposal_version_id' => $version?->id,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'payload' => ['message' => $message] + $payload,
            'occurred_at' => now(),
        ]);
    }

    private function appendDownloadUrls(CommercialProposal $proposal): void
    {
        if (!$proposal->relationLoaded('files')) {
            return;
        }

        foreach ($proposal->files as $file) {
            $file->setAttribute(
                'download_url',
                $this->fileService->temporaryUrl($file->storage_path, 60, $proposal->organization)
            );
        }
    }

    private function appendExportUrls(CommercialProposal $proposal): void
    {
        if (!$proposal->relationLoaded('exports')) {
            return;
        }

        foreach ($proposal->exports as $export) {
            $this->appendExportUrl($export, $proposal);
        }
    }

    private function appendExportUrl(CommercialProposalExport $export, CommercialProposal $proposal): void
    {
        $export->setAttribute(
            'download_url',
            $this->fileService->temporaryUrl($export->storage_path, 60, $proposal->organization)
        );
    }

    private function block(string $code): never
    {
        throw new CommercialProposalWorkflowException([$this->blocker($code)], trans_message("commercial_proposals.blockers.{$code}"));
    }

    /**
     * @return array{code:string,message:string}
     */
    private function blocker(string $key): array
    {
        return [
            'code' => $key,
            'message' => trans_message("commercial_proposals.blockers.{$key}"),
        ];
    }

    /**
     * @param list<array{code:string,message:string}> $blockers
     */
    private function firstBlockerMessage(array $blockers): string
    {
        return $blockers[0]['message'] ?? trans_message('commercial_proposals.errors.workflow_conflict');
    }
}
