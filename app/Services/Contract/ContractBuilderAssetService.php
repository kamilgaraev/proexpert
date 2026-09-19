<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractBuilderAssetService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractOrganizationViewService $views, private readonly FileService $files) {}

    public function upload(User $actor, int $organizationId, int $contractId, UploadedFile $file, string $kind, string $key): array
    {
        $permission = $kind === 'evidence' ? 'contracts.revisions.record_external' : 'contracts.edit';
        if ((int) $actor->current_organization_id !== $organizationId || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        $this->views->find($actor, $organizationId, $contractId);
        if (!in_array($kind, ['attachment', 'evidence'], true) || trim($key) === '' || mb_strlen($key) > 191 || !$file->isValid()
            || $file->getSize() < 1 || $file->getSize() > 20 * 1024 * 1024) {
            $this->invalid();
        }
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $mime = $file->getMimeType();
        if ($name === '' || mb_strlen($name) > 191 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || !in_array($mime, ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/png', 'image/jpeg', 'text/plain'], true)) {
            $this->invalid();
        }
        $bytes = file_get_contents($file->getRealPath(), false, null, 0, 20 * 1024 * 1024 + 1);
        if ($bytes === false || strlen($bytes) !== $file->getSize() || strlen($bytes) > 20 * 1024 * 1024) {
            $this->invalid();
        }
        $hash = hash('sha256', $bytes);
        $fingerprint = hash('sha256', json_encode([$actor->id, $kind, $name, $mime, $hash], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $kind, $key, $name, $mime, $bytes, $hash, $fingerprint): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            if (!$contract->parties()->where('linked_organization_id', $organizationId)->exists()) {
                throw new AuthorizationException;
            }
            DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $existing = DB::table('contract_builder_assets')->where('contract_id', $contractId)->where('organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new ContractBuilderException('contracts.builder_draft_conflict', 409);
                }
                return $this->present($existing);
            }
            $organization = Organization::findOrFail($organizationId);
            $path = $this->files->putContent($bytes, 'contracts/'.$contractId.'/builder/assets', $hash, 'private', $organization);
            if ($path === false) {
                throw new ContractBuilderException('contracts.builder_asset_storage_failed', 503);
            }
            $id = DB::table('contract_builder_assets')->insertGetId([
                'contract_id' => $contractId, 'organization_id' => $organizationId, 'created_by' => $actor->id,
                'kind' => $kind, 'name' => $name, 'mime_type' => $mime, 'size' => strlen($bytes), 'sha256' => $hash,
                'storage_path' => $path, 'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now(),
            ]);

            return $this->present(DB::table('contract_builder_assets')->where('id', $id)->firstOrFail());
        });
    }

    public function attachments(User $actor, int $organizationId, int $contractId, array $ids, array $shared): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        if (count($ids) > 50 || !array_is_list($ids) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
            || array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1) !== []) {
            $this->invalid();
        }
        $rows = DB::table('contract_builder_assets')->where('contract_id', $contractId)->where('kind', 'attachment')->whereIn('id', $ids)
            ->where(fn ($query) => $query->where('organization_id', $organizationId)->orWhereIn('id', array_column($shared, 'id')))->get()->keyBy('id');
        if ($rows->count() !== count($ids) || $rows->sum('size') > 100 * 1024 * 1024) {
            $this->invalid();
        }

        return array_map(fn (int $id): array => $this->present($rows[$id]), $ids);
    }

    public function evidence(User $actor, int $organizationId, int $contractId, int $id): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $asset = DB::table('contract_builder_assets')->where('id', $id)->where('contract_id', $contractId)
            ->where('organization_id', $organizationId)->where('kind', 'evidence')->firstOrFail();

        return $this->present($asset);
    }

    public function download(User $actor, int $organizationId, int $contractId, int $id): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $asset = DB::table('contract_builder_assets')->where('id', $id)->where('contract_id', $contractId)->firstOrFail();
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        if ((int) $asset->organization_id !== $organizationId) {
            $reference = json_encode([['id' => $id]], JSON_THROW_ON_ERROR);
            $published = DB::table('contract_builder_revisions')->where('instance_id', $instance->id)->whereRaw('attachments @> ?::jsonb', [$reference])->exists();
            $proposed = DB::table('contract_builder_proposals')->where('instance_id', $instance->id)
                ->where('recipient_organization_id', $organizationId)->whereRaw("content->'attachments' @> ?::jsonb", [$reference])->exists();
            $confirmed = DB::table('contract_revision_confirmations')->where('instance_id', $instance->id)->whereRaw("proof->>'id' = ?", [(string) $id])->exists();
            if (!$published && !$proposed && !$confirmed) {
                throw new AuthorizationException;
            }
        }
        try {
            $organization = Organization::findOrFail($asset->organization_id);
            $url = $this->files->temporaryUrl($asset->storage_path, 5, $organization, [
                'ResponseContentType' => $asset->mime_type,
                'ResponseContentDisposition' => "attachment; filename*=UTF-8''".rawurlencode($asset->name),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Contract asset download failed', ['contract_id' => $contractId, 'asset_id' => $id, 'exception' => $exception::class]);
            throw new ContractBuilderException('contracts.builder_asset_storage_failed', 503);
        }

        return ['asset' => $this->present($asset), 'url' => $url, 'expires_in' => 300];
    }

    private function present(object $asset): array
    {
        return ['id' => (int) $asset->id, 'organization_id' => (int) $asset->organization_id, 'kind' => $asset->kind,
            'name' => $asset->name, 'mime_type' => $asset->mime_type, 'size' => (int) $asset->size, 'sha256' => $asset->sha256];
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_asset_invalid', 422);
    }
}
