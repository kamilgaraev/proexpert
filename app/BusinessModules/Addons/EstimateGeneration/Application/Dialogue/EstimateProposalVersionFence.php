<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EstimateProposalVersionFence
{
    /** @return array<string, mixed> */
    public function capture(EstimateGenerationSession $session): array
    {
        $session->loadMissing('documents:id,session_id,source_version,checksum_sha256,updated_at');
        $analysis = is_array($session->analysis_payload) ? $session->analysis_payload : [];
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];

        return [
            'state_version' => (int) $session->state_version,
            'stage4_version' => $this->hash($analysis['building_model'] ?? $analysis),
            'stage5_version' => $this->hash($analysis['technology_recommendations'] ?? $analysis['planning'] ?? []),
            'stage6_version' => $this->hash($analysis['calculation'] ?? $draft),
            'draft_version' => $this->hash($draft),
            'artifact_versions' => $session->documents->map(fn ($document): array => [
                'id' => (int) $document->id, 'source_version' => $document->source_version,
                'checksum' => $document->checksum_sha256, 'updated_at' => $document->updated_at?->toISOString(),
            ])->sortBy('id')->values()->all(),
            'catalog_version' => $draft['technology_identity']['version'] ?? $analysis['catalog_version'] ?? null,
            'price_version' => $draft['price_identity']['version'] ?? $draft['regional_context']['estimate_regional_price_version_id'] ?? null,
            'context_fingerprint' => 'sha256:'.hash('sha256', json_encode([
                'analysis' => $analysis,
                'draft' => $draft,
                'state_version' => (int) $session->state_version,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function hash(mixed $value): string
    {
        return 'sha256:'.hash('sha256', json_encode($value ?? [], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
