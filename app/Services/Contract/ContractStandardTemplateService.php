<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractStandardTemplateService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractStandardTemplates $templates) {}

    public function catalogue(User $actor, int $organizationId): array
    {
        $this->authorize($actor, $organizationId, 'view');

        return $this->templates->catalogue();
    }

    public function install(User $actor, int $organizationId, string $code): array
    {
        $this->authorize($actor, $organizationId, 'create');
        $fields = $this->templates->fields($code);

        return DB::transaction(function () use ($actor, $organizationId, $code, $fields): array {
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $ids = [];
            foreach ($fields as $name => $definition) {
                (new ContractVariableDefinitionValidator)->validate($definition);
                $ids[$name] = $this->materialize($actor, $organizationId, $code.':field:'.$name, 'variable', trans_message('contract_templates.labels.'.$name), $definition);
            }
            $content = $this->templates->content($code, $ids);
            (new ContractDocumentValidator)->validate($content, null, true);
            $id = $this->materialize($actor, $organizationId, $code.':template', 'template', trans_message('contract_templates.'.$code.'.title'), $content);
            $resolved = app(ContractLibraryService::class)->resolveTemplate($actor, $organizationId, $id, ContractStandardTemplates::VERSION);

            return ['template_id' => $id, 'template_version' => ContractStandardTemplates::VERSION, ...$resolved];
        });
    }

    public function systemFields(User $actor, int $organizationId): array
    {
        $this->authorize($actor, $organizationId, 'view');

        return (new ContractSystemFields)->catalogue();
    }

    public function installSystemField(User $actor, int $organizationId, string $code): array
    {
        $this->authorize($actor, $organizationId, 'create');
        $definition = (new ContractSystemFields)->definition($code);
        (new ContractVariableDefinitionValidator)->validate($definition);

        return DB::transaction(function () use ($actor, $organizationId, $code, $definition): array {
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $id = $this->materialize($actor, $organizationId, 'context:field:'.$code, 'variable',
                trans_message('contract_system_fields.'.str_replace('.', '_', $code)), $definition);

            return app(ContractLibraryService::class)->read($actor, $organizationId, $id, ContractStandardTemplates::VERSION);
        });
    }

    private function materialize(User $actor, int $organizationId, string $identity, string $kind, string $title, array $content): string
    {
        $key = ContractStandardTemplates::PREFIX.$identity;
        $fingerprint = hash('sha256', json_encode([$kind, $title, $content], JSON_THROW_ON_ERROR));
        $item = DB::table('contract_library_items')->where('organization_id', $organizationId)->where('creation_key', $key)->first();
        if ($item !== null) {
            if (!hash_equals($item->creation_fingerprint, $fingerprint)) {
                throw new ContractBuilderException('contracts.library_conflict', 409);
            }

            return $item->id;
        }
        $id = (string) Str::uuid();
        DB::table('contract_library_items')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'kind' => $kind,
            'creation_key' => $key, 'creation_fingerprint' => $fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contract_library_versions')->insert([
            'item_id' => $id, 'organization_id' => $organizationId, 'version_number' => ContractStandardTemplates::VERSION,
            'title' => $title, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'created_by' => $actor->id,
            'created_at' => now(), 'request_key' => $key, 'request_fingerprint' => $fingerprint,
            'status' => 'published', 'published_by' => $actor->id, 'published_at' => now(),
        ]);

        return $id;
    }

    private function authorize(User $actor, int $organizationId, string $action): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || (!$this->authorization->can($actor, 'contracts.create', ['organization_id' => $organizationId])
                && !$this->authorization->can($actor, 'contracts.library.'.$action, ['organization_id' => $organizationId]))) {
            throw new AuthorizationException;
        }
    }
}
