<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use DomainException;
use Illuminate\Support\Facades\DB;

final class DesignReviewService
{
    public function commentsForPackage(DesignPackage $package, array $filters = [])
    {
        return DesignReviewComment::forOrganization((int) $package->organization_id)
            ->with(['section', 'artifact.currentVersion', 'version', 'sheet', 'author:id,name,email', 'assignee:id,name,email'])
            ->where('package_id', $package->id)
            ->when(!empty($filters['status']), static fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['severity']), static fn ($query) => $query->where('severity', (string) $filters['severity']))
            ->orderByRaw("CASE severity WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();
    }

    public function createComment(DesignPackage $package, int $userId, array $payload): DesignReviewComment
    {
        return DB::transaction(function () use ($package, $userId, $payload): DesignReviewComment {
            $round = $this->openRound($package, $userId, (string) ($payload['review_type'] ?? 'norm_control'));
            $target = $this->validatedTarget($package, $payload);

            return DesignReviewComment::query()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'package_id' => $package->id,
                'round_id' => $round->id,
                'section_id' => $target['section_id'],
                'artifact_id' => $target['artifact_id'],
                'version_id' => $target['version_id'],
                'sheet_id' => $target['sheet_id'],
                'author_id' => $userId,
                'assignee_id' => $payload['assignee_id'] ?? null,
                'severity' => $payload['severity'] ?? 'warning',
                'status' => DesignReviewCommentStatusEnum::OPEN,
                'body' => $payload['body'],
                'bim_element_id' => $payload['bim_element_id'] ?? null,
                'due_date' => $payload['due_date'] ?? null,
                'metadata' => $payload['metadata'] ?? [],
            ])->fresh(['section', 'artifact.currentVersion', 'version', 'sheet', 'author:id,name,email', 'assignee:id,name,email']);
        });
    }

    public function updateComment(DesignReviewComment $comment, int $userId, array $payload): DesignReviewComment
    {
        $status = $payload['status'] ?? $comment->status;
        $resolvedStatuses = [
            DesignReviewCommentStatusEnum::RESOLVED->value,
            DesignReviewCommentStatusEnum::ACCEPTED->value,
        ];
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        $comment->update([
            'assignee_id' => $payload['assignee_id'] ?? $comment->assignee_id,
            'severity' => $payload['severity'] ?? $comment->severity,
            'status' => $status,
            'body' => $payload['body'] ?? $comment->body,
            'response' => $payload['response'] ?? $comment->response,
            'due_date' => $payload['due_date'] ?? $comment->due_date,
            'resolved_by' => in_array($statusValue, $resolvedStatuses, true) ? $userId : $comment->resolved_by,
            'resolved_at' => in_array($statusValue, $resolvedStatuses, true) ? now() : $comment->resolved_at,
            'metadata' => $payload['metadata'] ?? $comment->metadata,
        ]);

        return $comment->fresh(['section', 'artifact.currentVersion', 'version', 'sheet', 'author:id,name,email', 'assignee:id,name,email']);
    }

    public function findComment(int $organizationId, int $commentId): ?DesignReviewComment
    {
        return DesignReviewComment::forOrganization($organizationId)->find($commentId);
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

            if (!$exists) {
                throw new DomainException(trans_message('design_management.errors.review_target_not_found'));
            }
        }

        if ($artifactId !== null) {
            $artifact = DesignArtifact::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->where('package_id', $packageId)
                ->whereKey($artifactId)
                ->first();

            if (!$artifact instanceof DesignArtifact) {
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

            if (!$version instanceof DesignArtifactVersion) {
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

            if (!$sheet instanceof DesignDocumentSheet) {
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
