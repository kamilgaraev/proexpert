<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractTemplateUpdateService
{
    public function __construct(private readonly ContractBuilderInstanceService $instances, private readonly ContractBuilderDraftService $drafts, private readonly ContractLibraryService $library) {}

    public function preview(User $actor, int $organizationId, int $contractId): array
    {
        $this->authorize($actor, $organizationId);
        $state = $this->instances->state($actor, $organizationId, $contractId);
        $revision = $state['revision'];
        if ($revision === null) {
            throw new ContractBuilderException('contracts.builder_input_invalid', 422);
        }
        $draft = $this->drafts->read($actor, $organizationId, $contractId);
        if ($draft !== null && $draft['base_revision'] !== $revision['revision_number']) {
            throw new ContractBuilderException('contracts.builder_conflict', 409);
        }
        $current = $draft ?? [...$revision, 'base_revision' => $revision['revision_number'], 'version' => 0];
        $origin = DB::table('contract_library_versions')->where('id', $current['template_version_id'])->where('organization_id', $organizationId)->first();
        if ($origin === null) {
            return ['available' => false, 'reason' => trans_message('contract_templates.update_other_organization')];
        }
        $latest = DB::table('contract_library_versions')->where('item_id', $origin->item_id)->where('organization_id', $organizationId)
            ->where('status', 'published')->orderByDesc('version_number')->firstOrFail();
        if ((int) $latest->version_number <= (int) $origin->version_number) {
            return ['available' => false, 'reason' => trans_message('contract_templates.update_current')];
        }
        $base = $this->library->resolveTemplate($actor, $organizationId, $origin->item_id, (int) $origin->version_number);
        $next = $this->library->resolveTemplate($actor, $organizationId, $origin->item_id, (int) $latest->version_number);
        if (($base['contract_profile_code'] ?? 'contract.work') !== ($next['contract_profile_code'] ?? 'contract.work')) {
            throw new ContractBuilderException('contract_templates.update_profile_changed', 422);
        }
        $values = [];
        $removed = [];
        $validator = new ContractVariableValueValidator;
        foreach ($current['values'] as $id => $value) {
            if (($current['definitions'][$id]['definition']['source']['kind'] ?? 'manual') !== 'manual') {
                continue;
            }
            $definition = $next['definitions'][$id]['definition'] ?? null;
            $compatible = $definition !== null && ($definition['source']['kind'] ?? 'manual') === 'manual'
                && $definition['type'] === $current['definitions'][$id]['definition']['type'];
            if ($compatible) {
                try {
                    $values[$id] = $validator->validate($definition, $value, static fn (): bool => true);
                    continue;
                } catch (ContractBuilderException) {
                }
            }
            if ($value !== null && $value !== '') {
                $removed[] = ['id' => $id, 'title' => $current['definitions'][$id]['title'], 'value' => $value];
            }
        }
        $hash = CanonicalJson::fingerprint([$current, $latest->id, $next]);
        $baseHash = CanonicalJson::fingerprint($base['document']);
        $localHash = CanonicalJson::fingerprint($current['document']);
        $nextHash = CanonicalJson::fingerprint($next['document']);

        return ['available' => true, 'template_id' => $origin->item_id, 'from_version' => (int) $origin->version_number,
            'target_version' => (int) $latest->version_number, 'target_version_id' => (int) $latest->id,
            'title' => $latest->title, 'base_revision' => (int) $current['base_revision'], 'expected_version' => (int) $current['version'],
            'comparison_hash' => $hash, 'has_local_changes' => $localHash !== $baseHash,
            'conflict' => $localHash !== $baseHash && $nextHash !== $baseHash && $localHash !== $nextHash,
            'base_text' => $this->text($base['document'], $base['definitions']),
            'local_text' => $this->text($current['document'], $current['definitions']),
            'template_text' => $this->text($next['document'], $next['definitions']),
            'definitions' => $next['definitions'], 'values' => $values, 'removed_values' => $removed,
            'local' => $current, 'next' => $next];
    }

    public function apply(User $actor, int $organizationId, int $contractId, array $input): array
    {
        $this->authorize($actor, $organizationId);
        if (!in_array($input['resolution'] ?? null, ['local', 'template'], true)
            || !is_string($input['request_key'] ?? null) || $input['request_key'] === '' || strlen($input['request_key']) > 191
            || !is_string($input['comparison_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $input['comparison_hash']) !== 1
            || !is_int($input['base_revision'] ?? null) || $input['base_revision'] < 1
            || !is_int($input['expected_version'] ?? null) || $input['expected_version'] < 0
            || !is_int($input['target_version'] ?? null) || $input['target_version'] < 1
            || (isset($input['values']) && !is_array($input['values']))) {
            throw new ContractBuilderException('contracts.builder_input_invalid', 422);
        }
        $this->instances->state($actor, $organizationId, $contractId);
        $requestHash = CanonicalJson::fingerprint(['template_update', $organizationId, $contractId, $input]);

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $input, $requestHash): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $draft = DB::table('contract_builder_drafts')->where('instance_id', $instance->id)->where('organization_id', $organizationId)->first();
            if ($draft !== null) {
                $operation = DB::table('contract_builder_draft_operations')->where('draft_id', $draft->id)->where('request_key', $input['request_key'])->first();
                if ($operation !== null) {
                    if (!hash_equals($operation->fingerprint, $requestHash)) {
                        throw new ContractBuilderException('contracts.builder_conflict', 409);
                    }

                    return json_decode($operation->response, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $preview = $this->preview($actor, $organizationId, $contractId);
            if (!$preview['available'] || !hash_equals($preview['comparison_hash'], $input['comparison_hash'])
                || $preview['base_revision'] !== $input['base_revision'] || $preview['expected_version'] !== $input['expected_version']
                || $preview['target_version'] !== $input['target_version']) {
                throw new ContractBuilderException('contracts.builder_conflict', 409);
            }
            $keep = $input['resolution'] === 'local';
            if (!$keep && $preview['removed_values'] !== [] && !($input['acknowledge_removed_values'] ?? false)) {
                throw new ContractBuilderException('contract_templates.update_values_confirmation', 422);
            }
            $content = $keep ? $preview['local'] : $preview['next'];
            $values = $keep ? $preview['local']['values'] : ($input['values'] ?? $preview['values']);
            $manual = array_filter($values, static fn (string $id): bool => ($content['definitions'][$id]['definition']['source']['kind'] ?? 'manual') === 'manual', ARRAY_FILTER_USE_KEY);

            return $this->drafts->save($actor, $organizationId, $contractId, $preview['base_revision'], $preview['expected_version'],
                $content['document'], $manual, $input['request_key'], templateUpdate: [
                    'template_version_id' => $preview['target_version_id'], 'definitions' => $content['definitions'], 'blocks' => $content['blocks'],
                    'request_fingerprint' => $requestHash,
                ]);
        });
    }

    private function authorize(User $actor, int $organizationId): void
    {
        $authorization = app(AuthorizationService::class);
        if ((int) $actor->current_organization_id !== $organizationId
            || !$authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            || !$authorization->can($actor, 'contracts.library.view', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function text(array $node, array $definitions): string
    {
        if ($node['type'] === 'text') {
            return $node['text'];
        }
        if ($node['type'] === 'variable') {
            return '['.($definitions[$node['attrs']['variableId']]['title'] ?? $node['attrs']['variableId']).']';
        }
        if ($node['type'] === 'hardBreak') {
            return "\n";
        }
        $text = implode('', array_map(fn (array $child): string => $this->text($child, $definitions), $node['content'] ?? []));

        return $text.(in_array($node['type'], ['paragraph', 'heading', 'tableRow'], true) ? "\n" : '');
    }
}
