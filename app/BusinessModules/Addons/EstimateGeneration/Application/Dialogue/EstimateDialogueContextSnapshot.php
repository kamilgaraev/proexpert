<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final readonly class EstimateDialogueContextSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $stateVersion,
        public array $inputPayload,
        public array $analysisPayload,
        public array $draftPayload,
        public ProjectModelSnapshot $projectModel,
        public string $projectModelToken,
        public array $decisions,
        public ?array $technology,
        public ?array $completeness,
        public array $derivedQuantities,
        public array $artifacts,
    ) {}

    public function fingerprint(): string
    {
        return 'sha256:'.hash('sha256', $this->canonicalJson($this->fingerprintPayload()));
    }

    /** @return array<string, mixed> */
    public function versionFence(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'project_model_token' => $this->projectModelToken,
            'technology_run_id' => $this->technology['run_id'] ?? null,
            'technology_catalog_version' => $this->technology['catalog_version'] ?? null,
            'technology_catalog_hash' => $this->technology['catalog_hash'] ?? null,
            'completeness_run_id' => $this->completeness['run_id'] ?? null,
            'completeness_rule_version' => $this->completeness['rule_catalog_version'] ?? null,
            'completeness_rule_hash' => $this->completeness['rule_catalog_hash'] ?? null,
            'derived_quantities_version' => $this->hash($this->derivedQuantities),
            'draft_version' => $this->hash($this->draftPayload),
            'norm_version' => $this->draftPayload['normative_context_pin']['version_id']
                ?? $this->draftPayload['normative_identity']['version']
                ?? null,
            'price_version' => $this->draftPayload['price_identity']['version']
                ?? $this->draftPayload['regional_context']['estimate_regional_price_version_id']
                ?? null,
            'artifacts' => $this->artifacts,
            'context_fingerprint' => $this->fingerprint(),
        ];
    }

    /** @return array<string, mixed> */
    private function fingerprintPayload(): array
    {
        return [
            'scope' => [$this->organizationId, $this->projectId, $this->sessionId],
            'state_version' => $this->stateVersion,
            'input_payload' => $this->inputPayload,
            'project_model_token' => $this->projectModelToken,
            'project_model' => $this->projectModel,
            'decisions' => $this->decisions,
            'technology' => $this->technology,
            'completeness' => $this->completeness,
            'derived_quantities' => $this->derivedQuantities,
            'draft_payload' => $this->draftPayload,
            'artifacts' => $this->artifacts,
        ];
    }

    private function hash(mixed $value): string
    {
        return 'sha256:'.hash('sha256', $this->canonicalJson($value));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
