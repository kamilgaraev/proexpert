<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Illuminate\Pagination\LengthAwarePaginator;

final class ContractLibraryService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function list(User $actor, int $organizationId, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, $organizationId, 'view');
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);
        if ($perPage < 1 || $perPage > 100 || $page < 1
            || (isset($filters['kind']) && !in_array($filters['kind'], ['template', 'block', 'variable'], true))
            || (isset($filters['status']) && !in_array($filters['status'], ['draft', 'published'], true))
            || (isset($filters['search']) && (!is_string($filters['search']) || mb_strlen($filters['search']) > 255))) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
        $latest = DB::table('contract_library_versions')->where('organization_id', $organizationId)
            ->selectRaw('item_id, MAX(version_number) AS latest_version')->groupBy('item_id');
        if (isset($filters['status'])) {
            $latest->where('status', $filters['status']);
        }
        $query = DB::table('contract_library_items as items')
            ->joinSub($latest, 'latest', 'latest.item_id', '=', 'items.id')
            ->join('contract_library_versions as versions', function ($join): void {
                $join->on('versions.item_id', '=', 'items.id')->on('versions.version_number', '=', 'latest.latest_version');
            })
            ->where('items.organization_id', $organizationId)
            ->where('items.creation_key', 'not like', ContractStandardTemplates::PREFIX.'%')
            ->where('items.is_archived', (bool) ($filters['archived'] ?? false))
            ->select(['items.id', 'items.organization_id', 'items.kind', 'items.is_archived', 'items.lock_version', 'items.updated_at',
                'versions.version_number', 'versions.title', 'versions.status']);
        if (isset($filters['kind'])) {
            $query->where('items.kind', $filters['kind']);
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $query->where('versions.title', 'ilike', '%'.addcslashes($filters['search'], '%_\\').'%');
        }

        return $query->orderByDesc('items.updated_at')->orderBy('items.id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function definitions(User $actor, int $organizationId, array $references): array
    {
        $this->authorize($actor, $organizationId, 'view');
        if (!array_is_list($references) || count($references) > 500) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
        $requested = [];
        foreach ($references as $reference) {
            if (!is_array($reference) || !is_string($reference['id'] ?? null) || !Str::isUuid($reference['id'])
                || !is_int($reference['version'] ?? null) || $reference['version'] < 1 || count($reference) !== 2) {
                throw new ContractBuilderException('contracts.library_input_invalid', 422);
            }
            $id = strtolower($reference['id']);
            if (isset($requested[$id])) {
                throw new ContractBuilderException('contracts.library_input_invalid', 422);
            }
            $requested[$id] = $reference['version'];
        }
        if ($requested === []) {
            return [];
        }
        $query = DB::table('contract_library_versions as versions')
            ->join('contract_library_items as items', 'items.id', '=', 'versions.item_id')
            ->where('items.organization_id', $organizationId)->where('versions.organization_id', $organizationId)
            ->where('items.kind', 'variable')
            ->where(function ($query) use ($requested): void {
                foreach ($requested as $id => $number) {
                    $query->orWhere(function ($pair) use ($id, $number): void {
                        $pair->where('versions.item_id', $id)->where('versions.version_number', $number);
                    });
                }
            });
        $sizes = (clone $query)->selectRaw('octet_length(versions.content::text) AS content_bytes')->get();
        if ($sizes->count() !== count($requested)) {
            throw new ModelNotFoundException;
        }
        if ($sizes->sum('content_bytes') > 10485760) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
        $rows = $query->get(['versions.id', 'versions.item_id', 'versions.version_number', 'versions.title', 'versions.content', 'versions.status', 'items.is_archived']);
        $definitions = [];
        foreach ($rows as $row) {
            $definitions[$row->item_id] = [
                'id' => $row->item_id, 'version' => (int) $row->version_number, 'version_id' => (int) $row->id,
                'title' => $row->title, 'definition' => json_decode($row->content, true, 512, JSON_THROW_ON_ERROR),
                'status' => $row->status, 'is_archived' => (bool) $row->is_archived,
            ];
        }

        return $definitions;
    }

    public function create(User $actor, int $organizationId, string $kind, string $title, array $content, string $key): array
    {
        $this->authorize($actor, $organizationId, 'create');
        if (str_starts_with($key, ContractStandardTemplates::PREFIX)) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
        $this->validate($kind, $title, $content, $key);
        $fingerprint = $this->fingerprint([$kind, $title, $content]);

        return DB::transaction(function () use ($actor, $organizationId, $kind, $title, $content, $key, $fingerprint): array {
            Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('contract_library_items')->where('organization_id', $organizationId)->where('creation_key', $key)->first();
            if ($existing !== null) {
                $this->assertSameRequest($existing->creation_fingerprint, $fingerprint);

                return $this->result($existing, 1);
            }
            $id = (string) Str::uuid();
            DB::table('contract_library_items')->insert([
                'id' => $id, 'organization_id' => $organizationId, 'kind' => $kind,
                'creation_key' => $key, 'creation_fingerprint' => $fingerprint,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $item = $this->item($organizationId, $id);
            $this->insertVersion($item, $actor, 1, $title, $content, $key, $fingerprint);

            return $this->result($item, 1);
        });
    }

    public function read(User $actor, int $organizationId, string $itemId, int $number): array
    {
        $item = $this->item($organizationId, $itemId);
        $this->authorizeRead($actor, $organizationId, $item);

        return $this->result($item, $number);
    }

    public function calculateTemplate(User $actor, int $organizationId, string $itemId, int $number, array $input): array
    {
        $resolved = $this->resolveTemplate($actor, $organizationId, $itemId, $number);
        $types = array_column($resolved['definitions'], 'definition', 'id');
        $snapshots = app(ContractEntityCatalog::class)->snapshots($actor, $organizationId, $types, $input);
        $sourceValues = app(ContractEntityCatalog::class)->sourceValues($actor, $organizationId, $types, $input);
        $values = (new ContractFormulaEngine)->calculate($types, $input, ContractEntityCatalog::accessible($snapshots), static fn (string $id): mixed => $sourceValues[$id]);

        return [
            'template_id' => $itemId, 'template_version' => $number, 'values' => $values,
            'entity_snapshots' => $snapshots,
            'html' => (new ContractDocumentRenderer)->render($resolved['document'], $resolved['definitions'], $values, $snapshots),
        ];
    }

    public function resolveTemplate(User $actor, int $organizationId, string $itemId, int $number): array
    {
        $item = $this->item($organizationId, $itemId);
        $this->authorizeRead($actor, $organizationId, $item);
        $version = $this->publishedVersion($organizationId, $itemId, $number, 'template');

        return (new ContractDocumentResolver)->resolve($version['content'], fn (string $id, int $version, string $kind): array => $this->publishedVersion($organizationId, $id, $version, $kind));
    }

    public function revise(User $actor, int $organizationId, string $itemId, int $expectedVersion, string $title, array $content, string $key): array
    {
        $this->authorize($actor, $organizationId, 'create');

        return DB::transaction(function () use ($actor, $organizationId, $itemId, $expectedVersion, $title, $content, $key): array {
            $item = $this->item($organizationId, $itemId, true);
            $this->assertEditable($item);
            $this->validate($item->kind, $title, $content, $key);
            $fingerprint = $this->fingerprint([$expectedVersion, $title, $content]);
            $existing = DB::table('contract_library_versions')->where('item_id', $itemId)->where('request_key', $key)->first();
            if ($existing !== null) {
                $this->assertSameRequest($existing->request_fingerprint, $fingerprint);

                return $this->result($item, (int) $existing->version_number);
            }
            $this->assertVersion($item, $expectedVersion);
            if ($item->is_archived) {
                throw new ContractBuilderException('contracts.library_archived', 409);
            }
            $number = (int) DB::table('contract_library_versions')->where('item_id', $itemId)->max('version_number') + 1;
            $this->insertVersion($item, $actor, $number, $title, $content, $key, $fingerprint);
            $this->advance($item);

            return $this->result($item, $number);
        });
    }

    public function publish(User $actor, int $organizationId, string $itemId, int $number, int $expectedVersion): array
    {
        $this->authorize($actor, $organizationId, 'publish');

        return DB::transaction(function () use ($actor, $organizationId, $itemId, $number, $expectedVersion): array {
            $item = $this->item($organizationId, $itemId, true);
            $this->assertEditable($item);
            $version = $this->result($item, $number)['version'];
            if ($version['status'] === 'published' && (int) $item->lock_version === $expectedVersion + 1) {
                return $this->result($item, $number);
            }
            $this->assertVersion($item, $expectedVersion);
            if ($item->is_archived || $version['status'] !== 'draft') {
                throw new ContractBuilderException('contracts.library_conflict', 409);
            }
            $this->validate($item->kind, $version['title'], $version['content'], $version['request_key']);
            if (in_array($item->kind, ['template', 'block'], true)) {
                $resolved = (new ContractDocumentResolver)->resolve($version['content'], fn (string $id, int $dependencyVersion, string $kind): array => $this->publishedVersion($organizationId, $id, $dependencyVersion, $kind));
                if ($item->kind === 'template') {
                    (new ContractRevisionTermsCompiler)->validateBasis($resolved);
                }
            }
            DB::table('contract_library_versions')->where('id', $version['id'])->update([
                'status' => 'published', 'published_by' => $actor->id, 'published_at' => now(),
            ]);
            $this->advance($item);

            return $this->result($item, $number);
        });
    }

    public function archive(User $actor, int $organizationId, string $itemId, int $expectedVersion, bool $archived): array
    {
        $this->authorize($actor, $organizationId, 'archive');

        return DB::transaction(function () use ($organizationId, $itemId, $expectedVersion, $archived): array {
            $item = $this->item($organizationId, $itemId, true);
            $this->assertEditable($item);
            if ((int) $item->lock_version === $expectedVersion + 1 && (bool) $item->is_archived === $archived) {
                return (array) $item;
            }
            $this->assertVersion($item, $expectedVersion);
            $item->is_archived = $archived;
            $this->advance($item);

            return (array) $item;
        });
    }

    private function authorizeRead(User $actor, int $organizationId, stdClass $item): void
    {
        if (!str_starts_with($item->creation_key, ContractStandardTemplates::PREFIX)
            || (int) $actor->current_organization_id !== $organizationId
            || (!$this->authorization->can($actor, 'contracts.create', ['organization_id' => $organizationId])
                && !$this->authorization->can($actor, 'contracts.library.create', ['organization_id' => $organizationId]))) {
            $this->authorize($actor, $organizationId, 'view');
        }
    }

    private function assertEditable(stdClass $item): void
    {
        if (str_starts_with($item->creation_key, ContractStandardTemplates::PREFIX)) {
            throw new ContractBuilderException('contract_templates.readonly', 422);
        }
    }

    private function authorize(User $actor, int $organizationId, string $action): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.library.'.$action, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function publishedVersion(int $organizationId, string $id, int $number, string $kind): array
    {
        $item = $this->item($organizationId, $id);
        $version = $this->result($item, $number)['version'];
        if ($item->kind !== $kind || $item->is_archived || $version['status'] !== 'published') {
            throw new ContractBuilderException('contracts.builder_document_invalid', 422);
        }

        return $version;
    }

    private function item(int $organizationId, string $id, bool $lock = false): stdClass
    {
        $query = DB::table('contract_library_items')->where('organization_id', $organizationId)->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new ModelNotFoundException;
    }

    private function result(stdClass $item, int $number): array
    {
        $version = DB::table('contract_library_versions')->where('item_id', $item->id)
            ->where('organization_id', $item->organization_id)->where('version_number', $number)->first() ?? throw new ModelNotFoundException;
        $data = (array) $version;
        $data['content'] = json_decode($version->content, true, 512, JSON_THROW_ON_ERROR);

        return ['item' => (array) $item, 'version' => $data];
    }

    private function insertVersion(stdClass $item, User $actor, int $number, string $title, array $content, string $key, string $fingerprint): void
    {
        DB::table('contract_library_versions')->insert([
            'item_id' => $item->id, 'organization_id' => $item->organization_id, 'version_number' => $number,
            'title' => $title, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'created_by' => $actor->id,
            'created_at' => now(), 'request_key' => $key, 'request_fingerprint' => $fingerprint,
        ]);
    }

    private function advance(stdClass $item): void
    {
        $item->lock_version = (int) $item->lock_version + 1;
        DB::table('contract_library_items')->where('id', $item->id)->update([
            'is_archived' => $item->is_archived, 'lock_version' => $item->lock_version, 'updated_at' => now(),
        ]);
        $item->updated_at = DB::table('contract_library_items')->where('id', $item->id)->value('updated_at');
    }

    private function assertVersion(stdClass $item, int $version): void
    {
        if ($version < 1 || (int) $item->lock_version !== $version) {
            throw new ContractBuilderException('contracts.library_conflict', 409);
        }
    }

    private function assertSameRequest(string $saved, string $current): void
    {
        if (!hash_equals($saved, $current)) {
            throw new ContractBuilderException('contracts.library_conflict', 409);
        }
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function validate(string $kind, string $title, array $content, string $key): void
    {
        if ($kind === 'variable') {
            (new ContractVariableDefinitionValidator)->validate($content);
        }
        if (in_array($kind, ['template', 'block'], true)) {
            (new ContractDocumentValidator)->validate($content, null, true);
        }
        if (!in_array($kind, ['template', 'block', 'variable'], true) || trim($title) === '' || mb_strlen($title) > 255
            || trim($key) === '' || strlen($key) > 191 || array_is_list($content)
            || strlen(json_encode($content, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
    }
}
