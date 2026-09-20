<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\DTOs\Contract\ContractDossierCreationResult;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractTemplateCardService
{
    public function prepare(User $actor, int $organizationId, array $input): array
    {
        $parties = app(ContractPartyPreviewService::class)->preview($actor, $organizationId, $input);
        if ($parties['reason'] !== null || $parties['first_party'] === null || $parties['second_party'] === null) {
            throw new ContractBuilderException('contracts.template_parties_required', 422);
        }
        $context = [
            'project' => ['name' => Project::findOrFail($input['project_id'])->name],
            'contract' => ['number' => $input['number'] ?? null, 'date' => $input['date'] ?? null],
            'first_party' => $parties['first_party'], 'second_party' => $parties['second_party'],
        ];
        $template = $input['template'];
        $resolved = app(ContractLibraryService::class)->resolveTemplate($actor, $organizationId, $template['template_id'], $template['template_version']);
        $resolved['contract_profile_code'] ??= 'contract.work';
        $context['contract']['profile_code'] = $resolved['contract_profile_code'];
        $types = array_column($resolved['definitions'], 'definition', 'id');
        $values = $template['values'];
        $catalog = app(ContractEntityCatalog::class);
        $snapshots = $catalog->snapshots($actor, $organizationId, $types, $values);
        $sources = $catalog->sourceValues($actor, $organizationId, $types, $values, null, $context);
        if (($input['resolve_only'] ?? false) === true) {
            return [...$resolved, 'values' => $values + $sources, 'entity_snapshots' => $snapshots,
                'template_id' => $template['template_id'], 'template_version' => $template['template_version'],
                'parties' => [$parties['first_party'], $parties['second_party']],
                'card' => null, 'html' => null, 'source_hash' => $this->fingerprint([$context, $sources, $snapshots])];
        }
        $calculated = (new ContractFormulaEngine)->calculate($types, $values, ContractEntityCatalog::accessible($snapshots), static fn (string $id): mixed => $sources[$id]);
        $revision = [...$resolved, 'values' => $calculated, 'entity_snapshots' => $snapshots];
        $compiled = (new ContractRevisionTermsCompiler)->compile($revision);

        return [
            ...$revision, 'template_id' => $template['template_id'], 'template_version' => $template['template_version'],
            'parties' => [$parties['first_party'], $parties['second_party']],
            'card' => $compiled,
            'source_hash' => $this->fingerprint([$context, $sources, $snapshots]),
            'html' => (new ContractDocumentRenderer)->render($resolved['document'], $resolved['definitions'], $calculated, $snapshots),
        ];
    }

    public function read(User $actor, int $organizationId, int $contractId): array
    {
        $state = app(ContractBuilderInstanceService::class)->state($actor, $organizationId, $contractId);
        $revision = $state['revision'];
        if ($revision === null) {
            return ['revision' => null, 'draft' => null];
        }
        $draft = app(ContractBuilderDraftService::class)->read($actor, $organizationId, $contractId);

        return [
            'revision' => ['number' => $revision['revision_number'], 'card' => (new ContractRevisionTermsCompiler)->compile($revision)],
            'draft' => $draft === null ? null : ['version' => $draft['version'], 'base_revision' => $draft['base_revision'],
                'card' => (new ContractRevisionTermsCompiler)->compile($draft)],
        ];
    }

    public function create(User $actor, int $organizationId, ContractDossierCreationInput $input, array $template): ContractDossierCreationResult
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !app(AuthorizationService::class)->can($actor, 'contracts.create', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        if ($input->contract->is_multi_project || $input->contract->is_self_execution
            || !in_array($input->contract->contract_side_type?->value, ['general_contract', 'contract', 'subcontract'], true)
            || !empty($input->contract->advance_payments) || ($input->contract->actual_advance_amount ?? 0) != 0) {
            throw new ContractBuilderException('contracts.template_card_invalid', 422);
        }
        $fingerprint = $this->fingerprint([get_object_vars($input->contract), $input->documentTitle, $input->profileCode,
            $input->documentMetadata, $input->confidentialityLevel, $template]);

        return DB::transaction(function () use ($actor, $organizationId, $input, $template, $fingerprint): ContractDossierCreationResult {
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $operation = DB::table('contract_template_card_operations')->where('organization_id', $organizationId)
                ->where('request_key', $input->normalizedIdempotencyKey())->first();
            if ($operation !== null) {
                if (!hash_equals($operation->fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.builder_conflict', 409);
                }
                app(ContractOrganizationViewService::class)->find($actor, $organizationId, (int) $operation->contract_id);
                $contract = Contract::findOrFail($operation->contract_id);

                return new ContractDossierCreationResult($contract, $contract->legalArchiveDocument()->firstOrFail(), true);
            }
            if (Contract::where('organization_id', $organizationId)->where('dossier_creation_key', $input->normalizedIdempotencyKey())->exists()) {
                throw new ContractBuilderException('contracts.builder_conflict', 409);
            }
            $prepared = $this->prepare($actor, $organizationId, [...get_object_vars($input->contract),
                'contract_side_type' => $input->contract->contract_side_type?->value, 'template' => $template]);
            if (!is_string($template['source_hash'] ?? null) || !hash_equals($prepared['source_hash'], $template['source_hash'])) {
                throw new ContractBuilderException('contracts.template_sources_changed', 409);
            }
            $terms = $prepared['card']['terms'];
            $attributes = get_object_vars($input->contract);
            $attributes['gp_calculation_type'] ??= GpCalculationTypeEnum::PERCENTAGE;
            foreach ($terms as $field => $value) {
                $attributes[$field] = match ($field) {
                    'total_amount', 'planned_advance_amount', 'warranty_retention_percentage' => (float) $value,
                    'warranty_retention_calculation_type' => GpCalculationTypeEnum::from($value),
                    default => $value,
                };
            }
            if (isset($terms['total_amount'])) {
                $attributes['base_amount'] = (float) $terms['total_amount'];
                $attributes['is_fixed_amount'] = true;
                $attributes['gp_calculation_type'] = GpCalculationTypeEnum::PERCENTAGE;
                $attributes['gp_percentage'] = 0.0;
                $attributes['gp_coefficient'] = null;
            }
            $result = app(ContractDossierCreationService::class)->create($organizationId, $actor, new ContractDossierCreationInput(
                new ContractDTO(...$attributes), $input->idempotencyKey, $input->documentTitle, $prepared['contract_profile_code'],
                $input->documentMetadata, $input->confidentialityLevel,
            ));
            $contract = $result->contract;
            if ($result->replayed) {
                throw new ContractBuilderException('contracts.builder_conflict', 409);
            }
            if ($terms !== []) {
                app(ContractAuditedMutationService::class)->update($contract,
                    [...$terms, ...(isset($terms['total_amount']) ? ['base_amount' => $terms['total_amount']] : [])],
                    'template_conditions_prepared', (int) $actor->id);
            }
            $revision = app(ContractBuilderInstanceService::class)->create($actor, $organizationId, (int) $contract->id,
                $template['template_id'], $template['template_version'], $template['values'], $input->normalizedIdempotencyKey(), initialCreation: true);
            if ($this->fingerprint($revision['values']) !== $this->fingerprint($prepared['values'])) {
                throw new ContractBuilderException('contracts.template_sources_changed', 409);
            }
            DB::table('contract_template_card_operations')->insert([
                'organization_id' => $organizationId, 'request_key' => $input->normalizedIdempotencyKey(),
                'fingerprint' => $fingerprint, 'contract_id' => $contract->id, 'created_at' => now(),
            ]);

            return new ContractDossierCreationResult($contract->refresh(), $result->document, false);
        });
    }

    private function fingerprint(mixed $value): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }
            if (is_object($value)) {
                $value = get_object_vars($value);
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }
                return array_map($normalize, $value);
            }
            return $value;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
