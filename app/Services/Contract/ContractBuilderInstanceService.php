<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractBuilderInstanceService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractLibraryService $library,
        private readonly ContractVariableValueValidator $values,
    ) {}

    public function state(User $actor, int $organizationId, int $contractId): array
    {
        $this->authorize($actor, $organizationId, 'contracts.view');
        $legacy = Contract::whereKey($contractId)->where('organization_id', $organizationId)
            ->whereDoesntHave('organizationViews')->exists();
        if ($legacy) {
            return ['requires_enrollment' => true, 'can_create' => false, 'can_adopt' => false, 'can_edit_draft' => false, 'revision' => null];
        }
        $view = $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->first();
        if ($instance !== null) {
            $revision = DB::table('contract_builder_revisions')->where('id', $instance->current_revision_id)
                ->where('instance_id', $instance->id)->firstOrFail();

            $canEditDraft = ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && $view->contract->parties()->where('linked_organization_id', $organizationId)->exists()
                && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId]);

            return ['can_create' => false, 'can_edit_draft' => $canEditDraft, 'revision' => $this->read($actor, $organizationId, $contractId, (int) $revision->revision_number)];
        }
        $contract = Contract::findOrFail($contractId);
        $parties = $contract->parties()->get();
        $canCreate = $contract->getRawOriginal('status') === 'draft' && $parties->count() === 2
            && $parties->contains('linked_organization_id', $organizationId)
            && in_array($contract->getRawOriginal('contract_side_type'), ['general_contract', 'contract', 'subcontract'], true)
            && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'contracts.library.view', ['organization_id' => $organizationId]);

        $canAdopt = $contract->getRawOriginal('status') === 'active' && (int) $contract->organization_id === $organizationId
            && $this->authorization->can($actor, 'contracts.revisions.adopt', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'contracts.library.view', ['organization_id' => $organizationId]);

        return ['can_create' => $canCreate, 'can_adopt' => $canAdopt, 'can_edit_draft' => false, 'revision' => null];
    }

    public function create(User $actor, int $organizationId, int $contractId, string $templateId, int $templateVersion, array $values, string $key, ?array $adoption = null): array
    {
        $this->authorize($actor, $organizationId, 'contracts.edit');
        if ($adoption !== null) {
            $this->authorize($actor, $organizationId, 'contracts.revisions.adopt');
            if (!is_string($adoption['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $adoption['fingerprint']) !== 1
                || !is_string($adoption['basis'] ?? null) || trim($adoption['basis']) === '' || mb_strlen($adoption['basis']) > 2000
                || array_diff(array_keys($adoption), ['fingerprint', 'basis']) !== []) {
                $this->invalid();
            }
        }
        if (trim($key) === '' || mb_strlen($key) > 191 || $templateVersion < 1 || !Str::isUuid($templateId)) {
            $this->invalid();
        }
        $fingerprint = hash('sha256', $this->json([$organizationId, $templateId, $templateVersion, (object) $values]));
        if ($adoption !== null) {
            $fingerprint = hash('sha256', $this->json([$fingerprint, $adoption]));
        }

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $templateId, $templateVersion, $values, $key, $fingerprint, $adoption): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $parties = $contract->parties()->orderBy('side')->get();
            if (!$parties->contains('linked_organization_id', $organizationId)) {
                throw new AuthorizationException;
            }
            $existing = DB::table('contract_builder_instances')->where('contract_id', $contractId)->first();
            if ($existing !== null) {
                $revision = DB::table('contract_builder_revisions')->where('instance_id', $existing->id)->where('revision_number', 1)->first();
                if ($revision === null || $revision->request_key !== $key || !hash_equals($revision->request_fingerprint, $fingerprint)) {
                    $this->conflict();
                }

                return $this->read($actor, $organizationId, $contractId, 1);
            }
            $adoptionId = null;
            if ($adoption !== null) {
                if ((int) $contract->organization_id !== $organizationId) {
                    throw new AuthorizationException;
                }
                $compatibility = new ContractBuilderLegacyCompatibility;
                $baseline = $compatibility->snapshot($contract);
                if (!hash_equals($compatibility->fingerprint($baseline), $adoption['fingerprint'])) {
                    $this->conflict();
                }
                $adoptionId = DB::table('contract_builder_legacy_adoptions')->insertGetId([
                    'contract_id' => $contractId, 'organization_id' => $organizationId, 'created_by' => $actor->id,
                    'fingerprint' => $adoption['fingerprint'], 'basis' => $adoption['basis'], 'baseline' => $this->json($baseline), 'created_at' => now(),
                ]);
            }
            if (($adoption === null && $contract->getRawOriginal('status') !== 'draft') || $parties->count() !== 2
                || !in_array($contract->getRawOriginal('contract_side_type'), ['general_contract', 'contract', 'subcontract'], true)) {
                $this->invalid();
            }
            $template = $this->published($actor, $organizationId, $templateId, $templateVersion, 'template');
            $resolved = $this->library->resolveTemplate($actor, $organizationId, $templateId, $templateVersion);
            $definitions = $resolved['definitions'];
            $types = array_column($definitions, 'definition', 'id');
            $entitySnapshots = app(ContractEntityCatalog::class)->snapshots($actor, $organizationId, $types, $values);
            $sourceValues = app(ContractEntityCatalog::class)->sourceValues($actor, $organizationId, $types, $values, null, ContractContextSourceFields::forContract($contract));
            $resolvedValues = (new ContractFormulaEngine($this->values))->calculate($types, $values, ContractEntityCatalog::accessible($entitySnapshots), static fn (string $id): mixed => $sourceValues[$id]);
            (new ContractDocumentRenderer)->render($resolved['document'], $definitions, $resolvedValues, $entitySnapshots);
            $snapshot = [
                'document' => $resolved['document'], 'definitions' => (object) $definitions, 'values' => (object) $resolvedValues,
                'blocks' => (object) $resolved['blocks'],
                'entity_snapshots' => (object) $entitySnapshots,
                'parties' => $parties->map(fn ($party): array => $party->only([
                    'side', 'role', 'linked_organization_id', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone',
                ]))->all(),
                'attachments' => [],
            ];
            $instanceId = DB::table('contract_builder_instances')->insertGetId([
                'contract_id' => $contractId, 'created_at' => now(), 'updated_at' => now(),
                'legacy_adoption_id' => $adoptionId,
            ]);
            $revisionId = DB::table('contract_builder_revisions')->insertGetId([
                'instance_id' => $instanceId, 'revision_number' => 1,
                'template_version_id' => $template['version']['id'], 'author_organization_id' => $organizationId,
                'created_by' => $actor->id, 'created_at' => now(),
                'request_key' => $key, 'request_fingerprint' => $fingerprint,
                'content_hash' => hash('sha256', $this->json([$template['version']['id'], $snapshot])),
                ...array_map(fn ($value): string => $this->json($value), $snapshot),
            ]);
            DB::table('contract_builder_instances')->where('id', $instanceId)->update(['current_revision_id' => $revisionId]);

            return $this->read($actor, $organizationId, $contractId, 1);
        });
    }

    public function read(User $actor, int $organizationId, int $contractId, int $revisionNumber): array
    {
        return DB::transaction(function () use ($actor, $organizationId, $contractId, $revisionNumber): array {
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $revision = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->where('revision_number', $revisionNumber)->firstOrFail();

            return [
                'id' => $revision->id, 'contract_id' => $contractId, 'revision_number' => $revision->revision_number,
                'base_revision_id' => $revision->base_revision_id, 'template_version_id' => $revision->template_version_id,
                'author_organization_id' => $revision->author_organization_id, 'created_by' => $revision->created_by,
                'created_at' => $revision->created_at, 'content_hash' => $revision->content_hash,
                'document' => json_decode($revision->document, true, 512, JSON_THROW_ON_ERROR),
                'definitions' => json_decode($revision->definitions, true, 512, JSON_THROW_ON_ERROR),
                'blocks' => json_decode($revision->blocks, true, 512, JSON_THROW_ON_ERROR),
                'values' => json_decode($revision->values, true, 512, JSON_THROW_ON_ERROR),
                'entity_snapshots' => json_decode($revision->entity_snapshots, true, 512, JSON_THROW_ON_ERROR),
                'parties' => json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR),
                'attachments' => json_decode($revision->attachments, true, 512, JSON_THROW_ON_ERROR),
            ];
        });
    }

    public function preview(User $actor, int $organizationId, int $contractId, int $revisionNumber): array
    {
        $revision = $this->read($actor, $organizationId, $contractId, $revisionNumber);

        return [
            'revision' => $revision,
            'html' => (new ContractDocumentRenderer)->render($revision['document'], $revision['definitions'], $revision['values'], $revision['entity_snapshots']),
        ];
    }

    private function published(User $actor, int $organizationId, string $id, int $number, string $kind): array
    {
        $result = $this->library->read($actor, $organizationId, $id, $number);
        if ($result['item']['kind'] !== $kind || $result['item']['is_archived'] || $result['version']['status'] !== 'published') {
            $this->invalid();
        }

        return $result;
    }

    private function authorize(User $actor, int $organizationId, string $permission): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function json(mixed $value): string
    {
        $normalize = function (mixed $input) use (&$normalize): mixed {
            if ($input instanceof \BackedEnum) {
                return $input->value;
            }
            if (is_object($input)) {
                $properties = get_object_vars($input);
                ksort($properties);

                return (object) array_map($normalize, $properties);
            }
            if (is_array($input)) {
                if (!array_is_list($input)) {
                    ksort($input);
                }

                return array_map($normalize, $input);
            }

            return $input;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_input_invalid', 422);
    }

    private function conflict(): never
    {
        throw new ContractBuilderException('contracts.builder_conflict', 409);
    }
}
