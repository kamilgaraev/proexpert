<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewCommentIssueMapping;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class DesignReviewService
{
    public function commentsForPackage(DesignPackage $package, array $filters = [])
    {
        return QualityDefect::forOrganization((int) $package->organization_id)
            ->projectIssues()
            ->where('project_id', $package->project_id)
            ->whereJsonContains('metadata->design_issue_context->package_id', (int) $package->id)
            ->when(! empty($filters['status']), static fn ($query) => $query->where('status', match ((string) $filters['status']) {
                'answered' => 'ready_for_review',
                'accepted', 'resolved' => 'resolved',
                default => (string) $filters['status'],
            }))
            ->when(! empty($filters['severity']), static function ($query) use ($filters): void {
                if ($filters['severity'] === 'blocking') {
                    $query->whereJsonContains('metadata->blocking->active', true);
                } else {
                    $query->whereIn('severity', $filters['severity'] === 'warning' ? ['major', 'critical'] : ['minor'])
                        ->where(static fn ($scope) => $scope->whereNull('metadata->blocking->active')->orWhereJsonContains('metadata->blocking->active', false));
                }
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();
    }

    public function legacyApiId(QualityDefect $issue): int
    {
        $mapping = DesignReviewCommentIssueMapping::query()
            ->where('quality_defect_id', $issue->id)
            ->first();
        if ($mapping !== null) {
            return (int) $mapping->legacy_api_id;
        }

        return DB::transaction(function () use ($issue): int {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [5261650, 1]);
            $existing = DesignReviewCommentIssueMapping::query()
                ->where('quality_defect_id', $issue->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return (int) $existing->legacy_api_id;
            }
            $next = ((int) DesignReviewCommentIssueMapping::query()->max('legacy_api_id')) + 1;
            $mapping = DesignReviewCommentIssueMapping::query()->create([
                'organization_id' => $issue->organization_id,
                'project_id' => $issue->project_id,
                'legacy_api_id' => $next,
                'quality_defect_id' => $issue->id,
            ]);

            return (int) $mapping->legacy_api_id;
        });
    }

    public function createComment(DesignPackage $package, int $userId, array $payload): QualityDefect
    {
        return DB::transaction(function () use ($package, $userId, $payload): QualityDefect {
            $round = $this->openRound($package, $userId, (string) ($payload['review_type'] ?? 'norm_control'));
            $target = $this->validatedTarget($package, $payload);

            $actor = User::query()->findOrFail($userId);
            $issues = app(DesignProjectIssueService::class);
            $issue = $issues->create($actor, (int) $package->organization_id, (int) $package->project_id, array_merge($payload, $target, [
                'package_id' => $package->id,
                'round_id' => $round->id,
                'title' => mb_strimwidth((string) $payload['body'], 0, 255, ''),
                'description' => $payload['body'],
                'severity' => match ($payload['severity'] ?? 'warning') {
                    'blocking' => 'critical', 'warning' => 'major', default => 'minor'
                },
            ]));

            return ($payload['severity'] ?? null) === 'blocking'
                ? $issues->setBlocking($issue, $actor, true, (string) $payload['body'])
                : $issue;
        });
    }

    public function updateComment(QualityDefect $comment, int $userId, array $payload): QualityDefect
    {
        $actor = User::query()->findOrFail($userId);
        if (! app(DesignModelSessionAccessService::class)->canAccessProject($actor, (int) $comment->organization_id, (int) $comment->project_id, 'design-management.review')) {
            throw new DomainException(trans_message('design_issues.errors.forbidden'));
        }

        return app(DesignProjectIssueService::class)->withRevision(
            $comment, (int) ($payload['expected_revision'] ?? $comment->getAttribute('row_version')),
            fn (QualityDefect $locked): QualityDefect => $this->applyCommentUpdate($locked, $userId, $payload),
        );
    }

    private function applyCommentUpdate(QualityDefect $comment, int $userId, array $payload): QualityDefect
    {
        $issueService = app(DesignProjectIssueService::class);
        if (isset($payload['assignee_id']) && (int) $payload['assignee_id'] !== (int) $comment->assigned_to) {
            $comment = $issueService->assign($comment, User::query()->findOrFail($userId), (int) $payload['assignee_id'], $payload['response'] ?? null);
        }
        $metadata = $comment->metadata ?? [];
        if (array_key_exists('response', $payload)) {
            $metadata['legacy_response'] = $payload['response'];
        }
        $comment->update([
            'title' => isset($payload['body']) ? mb_strimwidth((string) $payload['body'], 0, 255, '') : $comment->title,
            'description' => $payload['body'] ?? $comment->description,
            'due_date' => $payload['due_date'] ?? $comment->due_date,
            'metadata' => $metadata,
            'row_version' => (int) $comment->getAttribute('row_version') + 1,
        ]);
        $status = (string) ($payload['status'] ?? 'open');
        if (in_array($status, ['answered', 'resolved'], true) && $comment->canBeResolved()) {
            $comment = $issueService->resolve($comment, User::query()->findOrFail($userId), $payload['response'] ?? null);
        } elseif ($status === 'accepted') {
            $comment = $issueService->verify($comment, User::query()->findOrFail($userId), true, $payload['response'] ?? null);
        } elseif ($status === 'rejected' && $comment->status->value !== 'rejected') {
            $comment = app(\App\BusinessModules\Features\QualityControl\Services\QualityDefectService::class)->reject($comment, $userId, (string) ($payload['response'] ?? ''));
        }

        return $comment->fresh(['createdBy:id,name,email', 'assignedUser:id,name,email', 'statusHistory.changedBy']);
    }

    public function findComment(int $organizationId, int $commentId): ?QualityDefect
    {
        $mapping = DesignReviewCommentIssueMapping::query()
            ->where('organization_id', $organizationId)
            ->where('legacy_api_id', $commentId)
            ->first();
        if ($mapping === null) {
            return null;
        }

        return QualityDefect::query()
            ->forOrganization($organizationId)
            ->projectIssues()
            ->whereKey($mapping->quality_defect_id)
            ->first();
    }

    private function openRound(DesignPackage $package, int $userId, string $reviewType): DesignReviewRound
    {
        $round = DesignReviewRound::query()
            ->where('package_id', $package->id)
            ->where('review_type', $reviewType)
            ->where('status', 'open')
            ->latest('round_number')
            ->first();

        if ($round instanceof DesignReviewRound) {
            return $round;
        }

        $nextNumber = ((int) DesignReviewRound::query()
            ->where('package_id', $package->id)
            ->where('review_type', $reviewType)
            ->max('round_number')) + 1;

        if ($nextNumber <= 0) {
            throw new DomainException(trans_message('design_management.errors.review_round_failed'));
        }

        return DesignReviewRound::query()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'package_id' => $package->id,
            'created_by' => $userId,
            'round_number' => $nextNumber,
            'review_type' => $reviewType,
            'status' => 'open',
            'started_at' => now(),
            'metadata' => [],
        ]);
    }

    private function validatedTarget(DesignPackage $package, array $payload): array
    {
        $organizationId = (int) $package->organization_id;
        $projectId = (int) $package->project_id;
        $packageId = (int) $package->id;

        $sectionId = isset($payload['section_id']) ? (int) $payload['section_id'] : null;
        $artifactId = isset($payload['artifact_id']) ? (int) $payload['artifact_id'] : null;
        $versionId = isset($payload['version_id']) ? (int) $payload['version_id'] : null;
        $sheetId = isset($payload['sheet_id']) ? (int) $payload['sheet_id'] : null;

        if ($sectionId !== null) {
            $exists = DesignPackageSection::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->where('package_id', $packageId)
                ->whereKey($sectionId)
                ->exists();

            if (! $exists) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }
        }

        if ($artifactId !== null) {
            $artifact = DesignArtifact::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->where('package_id', $packageId)
                ->whereKey($artifactId)
                ->first();

            if (! $artifact instanceof DesignArtifact) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            if ($sectionId !== null && (int) $artifact->section_id !== $sectionId) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }
        }

        if ($versionId !== null) {
            $version = DesignArtifactVersion::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->whereKey($versionId)
                ->whereHas('artifact', static function ($query) use ($packageId, $artifactId, $sectionId): void {
                    $query->where('package_id', $packageId);

                    if ($artifactId !== null) {
                        $query->whereKey($artifactId);
                    }

                    if ($sectionId !== null) {
                        $query->where('section_id', $sectionId);
                    }
                })
                ->first();

            if (! $version instanceof DesignArtifactVersion) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            $artifactId ??= (int) $version->artifact_id;
        }

        if ($sheetId !== null) {
            $sheet = DesignDocumentSheet::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->where('package_id', $packageId)
                ->whereKey($sheetId)
                ->first();

            if (! $sheet instanceof DesignDocumentSheet) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            if ($sectionId !== null && (int) $sheet->section_id !== $sectionId) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            if ($artifactId !== null && (int) $sheet->artifact_id !== $artifactId) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            if ($versionId !== null && (int) $sheet->version_id !== $versionId) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }

            $sectionId ??= (int) $sheet->section_id;
            $artifactId ??= (int) $sheet->artifact_id;
            $versionId ??= (int) $sheet->version_id;
        }

        return [
            'section_id' => $sectionId,
            'artifact_id' => $artifactId,
            'version_id' => $versionId,
            'sheet_id' => $sheetId,
        ];
    }
}
