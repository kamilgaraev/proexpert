<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use InvalidArgumentException;

final readonly class EstimateAuditInput
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $snapshotToken,
        public int $cycle,
        public array $facts,
        public array $derivedQuantities,
        public array $draft,
        public array $evidence,
        public string $contractVersion,
        public ?AiSessionWireScope $wireScope = null,
        public string $partId = 'whole',
        public array $contextManifest = [],
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1
            || $cycle < 0 || $cycle > 2
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $contractVersion) !== 1
            || ! array_is_list($facts) || count($facts) > 10000
            || ! array_is_list($derivedQuantities) || count($derivedQuantities) > 2000
            || ! array_is_list($evidence) || count($evidence) > 20000
            || $draft === [] || array_is_list($draft)) {
            throw new InvalidArgumentException('estimate_audit_input_invalid');
        }
        if ($partId !== 'whole' && preg_match('/\Apart:[a-f0-9]{40}\z/', $partId) !== 1) {
            throw new InvalidArgumentException('estimate_audit_part_identity_invalid');
        }
        if ($contextManifest !== [] && (($contextManifest['contract'] ?? null) !== 'estimate-audit-context:v1'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($contextManifest['parent_fingerprint'] ?? '')) !== 1
            || ! is_int($contextManifest['total_facts'] ?? null) || $contextManifest['total_facts'] < count($facts))) {
            throw new InvalidArgumentException('estimate_audit_context_manifest_invalid');
        }
        $factIds = [];
        foreach ($facts as $fact) {
            $id = is_array($fact) ? ($fact['id'] ?? null) : null;
            if (! is_string($id) || trim($id) === '' || strlen($id) > 160 || isset($factIds[$id])) {
                throw new InvalidArgumentException('estimate_audit_fact_invalid');
            }
            $factIds[$id] = true;
        }
        foreach ($evidence as $record) {
            if (! is_array($record) || ! is_string($record['fact_id'] ?? null)
                || ! isset($factIds[$record['fact_id']]) || ! is_array($record['locator'] ?? null)) {
                throw new InvalidArgumentException('estimate_audit_evidence_invalid');
            }
        }
        // The full server snapshot can exceed a single wire request. It is never
        // sent before section/context partitioning and admission checks.
        $serverPayload = $this->canonicalPayload(false);
        $serverPayload['draft'] = $this->draft;
        $serverPayload['derived_quantities'] = $this->derivedQuantities;
        if (strlen($this->canonicalJson($serverPayload)) > ($partId === 'whole' ? 33_554_432 : 2_097_152)) {
            throw new InvalidArgumentException('estimate_audit_input_too_large');
        }
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(bool $exactValueReferences = true): array
    {
        $payload = [
            'schema_version' => 1,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'session_id' => $this->sessionId,
            'snapshot_token' => $this->snapshotToken,
            'cycle' => $this->cycle,
            'facts' => $this->facts,
            'derived_quantities' => EstimateAuditQuantityWireProjection::project($this->derivedQuantities),
            'draft' => EstimateAuditDraftWireProjection::project($this->draft, $this->derivedQuantities),
            'evidence' => $this->evidence,
            'contract_version' => $this->contractVersion,
            ...($this->partId === 'whole' ? [] : ['part_id' => $this->partId]),
            ...($this->contextManifest === [] ? [] : ['context_manifest' => $this->contextManifest]),
        ];

        return $exactValueReferences ? EstimateAuditExactValueWireProjection::forAudit($payload) : $payload;
    }

    public function fingerprint(): string
    {
        $payload = $this->canonicalPayload(false);
        // Replay binds the complete server artifact, including fields deduplicated on wire.
        $payload['draft'] = $this->draft;
        $payload['derived_quantities'] = $this->derivedQuantities;
        if ($this->wireScope?->creditOperationId !== null) {
            $payload['approved_operation_id'] = $this->wireScope->creditOperationId;
        }

        return hash('sha256', $this->canonicalJson($payload));
    }

    public function withDraft(array $draft, int $cycle): self
    {
        return new self(
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->snapshotToken,
            $cycle,
            $this->facts,
            $this->derivedQuantities,
            $draft,
            $this->evidence,
            $this->contractVersion,
            $this->wireScope,
            $this->partId,
            $this->contextManifest,
        );
    }

    public function withPart(array $draft, string $partId): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->snapshotToken, $this->cycle,
            $this->facts, $this->derivedQuantities, $draft, $this->evidence, $this->contractVersion, $this->wireScope, $partId, $this->contextManifest);
    }

    public function withContextPart(array $draft, string $partId, array $facts, array $quantities, array $evidence, array $manifest): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->snapshotToken, $this->cycle,
            $facts, $quantities, $draft, $evidence, $this->contractVersion, $this->wireScope, $partId, $manifest);
    }

    public function wireBytes(): int
    {
        return strlen($this->canonicalJson(['audit_input' => $this->canonicalPayload()]));
    }

    private function canonicalJson(array $payload): string
    {
        return json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
