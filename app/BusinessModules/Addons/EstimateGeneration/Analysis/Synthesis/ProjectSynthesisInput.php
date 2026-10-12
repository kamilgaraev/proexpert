<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use InvalidArgumentException;

final readonly class ProjectSynthesisInput
{
    /**
     * @param  list<string>  $sourceVersions
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $derivedQuantities
     * @param  list<array<string, mixed>>  $decisions
     * @param  array{arbiter:list<string>,geometry_expert:list<string>}  $roleFingerprints
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public array $sourceVersions,
        public array $facts,
        public array $derivedQuantities,
        public array $decisions,
        public array $roleFingerprints,
        public string $contractVersion,
        public ?AiSessionWireScope $wireScope = null,
        public string $partId = 'whole',
        public array $contextManifest = [],
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || $sourceVersions === [] || ! array_is_list($sourceVersions)
            || ! array_is_list($facts) || ! array_is_list($derivedQuantities) || ! array_is_list($decisions)
            || array_keys($roleFingerprints) !== ['arbiter', 'geometry_expert']
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $contractVersion) !== 1) {
            throw new InvalidArgumentException('project_synthesis_input_invalid');
        }
        foreach ($sourceVersions as $sourceVersion) {
            if (! is_string($sourceVersion) || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1) {
                throw new InvalidArgumentException('project_synthesis_source_version_invalid');
            }
        }
        if ($partId !== 'whole' && preg_match('/\Apart:[a-f0-9]{40}\z/', $partId) !== 1) {
            throw new InvalidArgumentException('project_synthesis_part_identity_invalid');
        }
        if ($contextManifest !== [] && (($contextManifest['contract'] ?? null) !== 'project-context-slice:v1'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($contextManifest['parent_fingerprint'] ?? '')) !== 1
            || ! is_int($contextManifest['total_facts'] ?? null) || $contextManifest['total_facts'] < count($facts))) {
            throw new InvalidArgumentException('project_synthesis_context_manifest_invalid');
        }
        foreach ($roleFingerprints as $fingerprints) {
            if (! array_is_list($fingerprints)) {
                throw new InvalidArgumentException('project_synthesis_role_fingerprint_invalid');
            }
            foreach ($fingerprints as $fingerprint) {
                if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                    throw new InvalidArgumentException('project_synthesis_role_fingerprint_invalid');
                }
            }
        }
    }

    public function fingerprint(): string
    {
        $payload = $this->canonicalPayload();
        if ($this->wireScope?->creditOperationId !== null) {
            $payload['approved_operation_id'] = $this->wireScope->creditOperationId;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function aggregateSourceVersion(): string
    {
        $versions = $this->sourceVersions;
        sort($versions, SORT_STRING);

        return count($versions) === 1
            ? $versions[0]
            : 'sha256:'.hash('sha256', implode("\0", $versions));
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        $sources = array_values(array_unique($this->sourceVersions));
        sort($sources, SORT_STRING);
        $roles = $this->roleFingerprints;
        foreach ($roles as &$fingerprints) {
            $fingerprints = array_values(array_unique($fingerprints));
            sort($fingerprints, SORT_STRING);
        }
        unset($fingerprints);

        return [
            'scope' => [
                'organization_id' => $this->organizationId,
                'project_id' => $this->projectId,
                'session_id' => $this->sessionId,
            ],
            'source_versions' => $sources,
            'facts' => $this->sorted($this->facts),
            'derived_quantities' => $this->sorted($this->derivedQuantities),
            'decisions' => $this->sorted($this->decisions),
            'role_fingerprints' => $roles,
            'contract_version' => $this->contractVersion,
            ...($this->partId === 'whole' ? [] : ['part_id' => $this->partId]),
            ...($this->contextManifest === [] ? [] : ['context_manifest' => $this->contextManifest]),
        ];
    }

    public function withPart(string $partId): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->sourceVersions, $this->facts,
            $this->derivedQuantities, $this->decisions, $this->roleFingerprints, $this->contractVersion, $this->wireScope, $partId, $this->contextManifest);
    }

    public function withContextPart(string $partId, array $facts, array $quantities, array $decisions): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->sourceVersions, $facts,
            $quantities, $decisions, $this->roleFingerprints, $this->contractVersion, $this->wireScope, $partId, [
                'contract' => 'project-context-slice:v1', 'parent_fingerprint' => $this->fingerprint(),
                'total_facts' => count($this->facts), 'total_derived_quantities' => count($this->derivedQuantities),
                'total_decisions' => count($this->decisions),
                'scope' => 'candidate_dependencies',
            ]);
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    private function sorted(array $records): array
    {
        usort($records, static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR)
            <=> json_encode($right, JSON_THROW_ON_ERROR));

        return $records;
    }
}
