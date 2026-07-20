<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowTemplate;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowTemplateStep;
use App\Models\User;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\DTO\WorkflowSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final class LegalWorkflowTemplateService
{
    private const ACTOR_TYPES = ['user', 'role', 'party', 'external'];

    public function __construct(
        private readonly ImmutableAuditIntegrityService $integrity,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    /** @param list<array<string, mixed>> $steps */
    public function createVersion(
        int $organizationId,
        string $code,
        string $name,
        array $steps,
        User $actor,
    ): LegalWorkflowTemplate {
        $code = trim($code);
        $name = trim($name);
        if (
            $organizationId < 1
            || (int) $actor->current_organization_id !== $organizationId
            || $code === ''
            || $name === ''
            || ! $actor->hasPermission(LegalWorkflowPermissions::MANAGE_TEMPLATES, ['organization_id' => $organizationId])
        ) {
            throw new DomainException('legal_workflow_template_access_denied');
        }

        $normalized = $this->normalizeDefinitions($steps, []);
        $definitionHash = hash('sha256', $this->canonicalJson($normalized));
        $connection = $this->database();

        return $connection->transaction(function () use (
            $organizationId,
            $code,
            $name,
            $actor,
            $normalized,
            $definitionHash,
            $connection,
        ): LegalWorkflowTemplate {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->select(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    ["legal-workflow-template:{$organizationId}:{$code}"],
                );
            }
            $head = $connection->table('legal_workflow_template_heads')
                ->where('organization_id', $organizationId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();
            $version = (int) $this->templateQuery()
                ->forOrganization($organizationId)
                ->where('code', $code)
                ->max('version') + 1;

            $template = $this->templateQuery()->create([
                'organization_id' => $organizationId,
                'code' => $code,
                'version' => $version,
                'name' => $name,
                'definition_hash' => $definitionHash,
                'created_by_user_id' => (int) $actor->id,
            ]);
            foreach ($normalized as $step) {
                $template->steps()->create([
                    'organization_id' => $organizationId,
                    'step_key' => $step['key'],
                    'label' => $step['label'],
                    'sequence' => $step['sequence'],
                    'parallel_group' => $step['parallel_group'],
                    'required' => $step['required'],
                    'policy_key' => $step['policy_key'],
                    'actor_type' => $step['actor_type'],
                    'actor_reference' => $step['actor_reference'],
                    'due_in_hours' => $step['due_in_hours'],
                    'settings' => $step['settings'],
                ]);
            }
            $template->load('steps');
            $this->assertIntegrity($template);

            $attributes = [
                'template_id' => $template->id,
                'updated_at' => now(),
            ];
            if ($head === null) {
                $connection->table('legal_workflow_template_heads')->insert([
                    'organization_id' => $organizationId,
                    'code' => $code,
                    'created_at' => now(),
                    ...$attributes,
                ]);
            } else {
                $connection->table('legal_workflow_template_heads')
                    ->where('organization_id', $organizationId)
                    ->where('code', $code)
                    ->update($attributes);
            }

            return $template;
        }, 3);
    }

    public function resolveForDocument(LegalArchiveDocument $document, ?int $templateId = null): LegalWorkflowTemplate
    {
        $organizationId = (int) $document->organization_id;
        if ($organizationId < 1) {
            throw new DomainException('legal_workflow_document_identity_required');
        }

        if ($templateId !== null) {
            $template = $this->templateQuery()
                ->forOrganization($organizationId)
                ->with('steps')
                ->find($templateId);
            if ($template instanceof LegalWorkflowTemplate) {
                $this->assertIntegrity($template);

                return $template;
            }
            throw new DomainException('legal_workflow_template_not_found');
        }

        $profileCode = trim((string) $document->type_profile_code);
        $candidateCodes = array_values(array_unique(array_filter([
            $profileCode,
            Str::before($profileCode, '.'),
            'default',
        ])));
        foreach ($candidateCodes as $code) {
            $templateId = $this->database()->table('legal_workflow_template_heads')
                ->where('organization_id', $organizationId)
                ->where('code', $code)
                ->value('template_id');
            if ($templateId !== null) {
                $template = $this->templateQuery()
                    ->forOrganization($organizationId)
                    ->with('steps')
                    ->find((int) $templateId);
                if ($template instanceof LegalWorkflowTemplate) {
                    $this->assertIntegrity($template);

                    return $template;
                }
            }
        }

        throw new DomainException('legal_workflow_template_not_found');
    }

    public function snapshot(LegalWorkflowTemplate $template, WorkflowOverride $override): WorkflowSnapshot
    {
        $this->assertIntegrity($template);
        $steps = $this->definitionsFromTemplate($template);

        return $this->snapshotFromDefinitions(
            (int) $template->organization_id,
            (string) $template->code,
            (int) $template->version,
            $steps,
            $override,
            (int) $template->id,
            (string) $template->definition_hash,
        );
    }

    /** @return list<array<string, mixed>> */
    private function definitionsFromTemplate(LegalWorkflowTemplate $template): array
    {
        return $template->steps->map(static fn (LegalWorkflowTemplateStep $step): array => [
            'key' => (string) $step->step_key,
            'label' => (string) $step->label,
            'sequence' => (int) $step->sequence,
            'parallel_group' => (string) $step->parallel_group,
            'required' => (bool) $step->required,
            'policy_key' => $step->policy_key === null ? null : (string) $step->policy_key,
            'actor_type' => (string) $step->actor_type,
            'actor_reference' => (string) $step->actor_reference,
            'due_in_hours' => $step->due_in_hours === null ? null : (int) $step->due_in_hours,
            'settings' => (array) ($step->settings ?? []),
        ])->all();
    }

    /** @param list<array<string, mixed>> $steps */
    public function snapshotFromDefinitions(
        int $organizationId,
        string $templateCode,
        int $templateVersion,
        array $steps,
        WorkflowOverride $override,
        int $templateId = 0,
        ?string $definitionHash = null,
    ): WorkflowSnapshot {
        foreach ($override->additionalSteps as $additionalStep) {
            if (($additionalStep['required'] ?? false) === true || ($additionalStep['policy_key'] ?? null) !== null) {
                throw new DomainException('legal_workflow_override_step_must_be_optional');
            }
        }
        $normalized = $this->normalizeDefinitions(
            [...$steps, ...$override->additionalSteps],
            $override->stepOverrides,
        );
        $payload = [
            'schema_version' => 2,
            'template_identity' => [
                'organization_id' => $organizationId,
                'template_id' => $templateId,
                'code' => $templateCode,
                'version' => $templateVersion,
                'definition_hash' => $definitionHash ?? hash('sha256', $this->canonicalJson($this->normalizeDefinitions($steps, []))),
            ],
            'override' => $override->snapshotPayload(),
            'steps' => $normalized,
        ];

        return new WorkflowSnapshot($payload, hash('sha256', $this->canonicalJson($payload)));
    }

    public function canonicalJson(mixed $payload): string
    {
        return $this->integrity->canonicalJson($payload);
    }

    public function assertIntegrity(LegalWorkflowTemplate $template): void
    {
        $actual = hash('sha256', $this->canonicalJson($this->normalizeDefinitions(
            $this->definitionsFromTemplate($template),
            [],
        )));
        if (! is_string($template->definition_hash) || ! hash_equals($template->definition_hash, $actual)) {
            throw new DomainException('legal_workflow_template_integrity_failed');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, array<string, mixed>>  $overrides
     * @return list<array<string, mixed>>
     */
    private function normalizeDefinitions(array $definitions, array $overrides): array
    {
        $normalized = [];
        $keys = [];
        $legalPolicySteps = 0;
        foreach ($definitions as $definition) {
            $key = trim((string) ($definition['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                throw new DomainException('legal_workflow_step_key_invalid');
            }
            $keys[$key] = true;
            $override = $overrides[$key] ?? [];
            if (array_diff(array_keys($override), [
                'enabled', 'sequence', 'parallel_group', 'actor_type', 'actor_reference', 'due_in_hours', 'due_at',
            ]) !== []) {
                throw new DomainException('legal_workflow_step_override_invalid');
            }
            $policyKey = $definition['policy_key'] ?? null;
            if ($policyKey !== null && $policyKey !== 'legal_review') {
                throw new DomainException('legal_workflow_policy_key_invalid');
            }
            if ($policyKey === 'legal_review') {
                $legalPolicySteps++;
                if (($definition['required'] ?? false) !== true) {
                    throw new DomainException('legal_workflow_mandatory_legal_step_required');
                }
            }
            if ($policyKey !== null && $override !== []) {
                $forbidden = ['enabled', 'sequence', 'parallel_group', 'actor_type', 'actor_reference'];
                if (array_intersect($forbidden, array_keys($override)) !== []) {
                    throw new DomainException('legal_workflow_mandatory_step_override_forbidden');
                }
            }
            if (($override['enabled'] ?? true) === false) {
                if (($definition['required'] ?? false) === true || $policyKey !== null) {
                    throw new DomainException('legal_workflow_required_step_disabled');
                }

                continue;
            }
            if (array_key_exists('actor_type', $override) && ! array_key_exists('actor_reference', $override)) {
                throw new DomainException('legal_workflow_actor_reference_required');
            }

            $step = array_replace($definition, $override);
            $actorType = trim((string) ($step['actor_type'] ?? ''));
            $actorReference = trim((string) ($step['actor_reference'] ?? ''));
            $sequence = filter_var($step['sequence'] ?? null, FILTER_VALIDATE_INT);
            $dueInHours = $step['due_in_hours'] ?? null;
            $dueAt = $step['due_at'] ?? null;
            if (! in_array($actorType, self::ACTOR_TYPES, true)) {
                throw new DomainException('legal_workflow_actor_type_invalid');
            }
            if ($actorReference === '') {
                throw new DomainException('legal_workflow_actor_reference_required');
            }
            if ($sequence === false || $sequence < 1) {
                throw new DomainException('legal_workflow_sequence_invalid');
            }
            if ($dueInHours !== null && (filter_var($dueInHours, FILTER_VALIDATE_INT) === false || (int) $dueInHours < 1 || (int) $dueInHours > 8760)) {
                throw new DomainException('legal_workflow_deadline_invalid');
            }
            if ($dueAt !== null) {
                try {
                    $parsedDueAt = CarbonImmutable::parse((string) $dueAt)->utc();
                    if ($parsedDueAt->isPast()) {
                        throw new DomainException('legal_workflow_deadline_invalid');
                    }
                    $dueAt = $parsedDueAt->toAtomString();
                } catch (\Throwable) {
                    throw new DomainException('legal_workflow_deadline_invalid');
                }
            }
            $label = trim((string) ($step['label'] ?? ''));
            if ($label === '') {
                throw new DomainException('legal_workflow_step_label_required');
            }
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'sequence' => (int) $sequence,
                'parallel_group' => trim((string) ($step['parallel_group'] ?? '')) ?: "sequence-{$sequence}",
                'required' => (bool) ($step['required'] ?? false),
                'policy_key' => $policyKey === null ? null : (string) $policyKey,
                'actor_type' => $actorType,
                'actor_reference' => $actorReference,
                'due_in_hours' => $dueInHours === null ? null : (int) $dueInHours,
                'due_at' => $dueAt,
                'settings' => is_array($step['settings'] ?? null) ? $step['settings'] : [],
            ];
        }
        if (array_diff(array_keys($overrides), array_keys($keys)) !== []) {
            throw new DomainException('legal_workflow_step_override_not_found');
        }

        if ($normalized === [] || $legalPolicySteps !== 1) {
            throw new DomainException('legal_workflow_mandatory_legal_step_required');
        }
        usort($normalized, static fn (array $left, array $right): int => [
            $left['sequence'], $left['parallel_group'], $left['key'],
        ] <=> [
            $right['sequence'], $right['parallel_group'], $right['key'],
        ]);

        return $normalized;
    }

    private function database(): ConnectionInterface
    {
        return $this->connection ?? LegalWorkflowTemplate::resolveConnection();
    }

    private function templateQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalWorkflowTemplate)->setConnection($this->database()->getName())->newQuery();
    }
}
