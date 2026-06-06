<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignReviewCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignReviewComment $comment */
        $comment = $this->resource;
        $status = $this->enumValue($comment->status);
        $severity = $this->enumValue($comment->severity);

        return [
            'id' => $comment->id,
            'organization_id' => $comment->organization_id,
            'project_id' => $comment->project_id,
            'package_id' => $comment->package_id,
            'round_id' => $comment->round_id,
            'section_id' => $comment->section_id,
            'artifact_id' => $comment->artifact_id,
            'version_id' => $comment->version_id,
            'sheet_id' => $comment->sheet_id,
            'author_id' => $comment->author_id,
            'assignee_id' => $comment->assignee_id,
            'severity' => $severity,
            'severity_label' => trans_message("design_management.review_comment_severities.{$severity}"),
            'status' => $status,
            'status_label' => trans_message("design_management.review_comment_statuses.{$status}"),
            'body' => $comment->body,
            'response' => $comment->response,
            'bim_element_id' => $comment->bim_element_id,
            'due_date' => $comment->due_date?->format('Y-m-d'),
            'resolved_by' => $comment->resolved_by,
            'resolved_at' => $comment->resolved_at?->toIso8601String(),
            'metadata' => $comment->metadata ?? [],
            'section' => $this->whenLoaded('section', fn () => $comment->section ? [
                'id' => $comment->section->id,
                'code' => $comment->section->code,
                'title' => $comment->section->title,
            ] : null),
            'artifact' => $this->whenLoaded('artifact', fn () => $comment->artifact ? [
                'id' => $comment->artifact->id,
                'document_code' => $comment->artifact->document_code,
                'title' => $comment->artifact->document_title ?: $comment->artifact->title,
            ] : null),
            'sheet' => $this->whenLoaded('sheet', fn () => $comment->sheet ? [
                'id' => $comment->sheet->id,
                'sheet_number' => $comment->sheet->sheet_number,
                'sheet_title' => $comment->sheet->sheet_title,
            ] : null),
            'author' => $this->whenLoaded('author', fn () => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
                'email' => $comment->author->email,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $comment->assignee ? [
                'id' => $comment->assignee->id,
                'name' => $comment->assignee->name,
                'email' => $comment->assignee->email,
            ] : null),
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
