<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class AssistantReportAccessService
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy) {}

    public function register(string $path, Organization $organization, User $user, array $sourceRefs, array $domains): array
    {
        $organizationId = (int) $organization->id;
        $this->assertReferences($user, $organizationId, $sourceRefs, $domains);
        if (! str_starts_with($path, 'org-'.$organizationId.'/') || str_contains($path, '..')) {
            throw new AccessDeniedHttpException();
        }
        $id = (string) Str::uuid();
        $expiresAt = now()->addDays(90);
        DB::table('ai_assistant_report_access')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'user_id' => $user->id,
            'storage_path' => $path, 'source_refs' => json_encode($sourceRefs, JSON_THROW_ON_ERROR),
            'required_domains' => json_encode(array_values(array_unique(array_merge(['reports'], $domains))), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = rtrim(strtr(base64_encode(encrypt(['report_access_id' => $id])), '+/', '-_'), '=');
        return ['download_url' => '/api/v1/ai-assistant/reports/'.$token.'/download', 'report_access_id' => $id, 'source_refs' => $sourceRefs];
    }

    public function resolve(string $token, User $user): object
    {
        $data = decrypt(base64_decode(strtr($token, '-_', '+/'), true));
        $id = is_array($data) ? ($data['report_access_id'] ?? null) : null;
        $record = is_string($id) ? DB::table('ai_assistant_report_access')->where('id', $id)->first() : null;
        if ($record === null) {
            throw new AccessDeniedHttpException();
        }
        if (now()->greaterThanOrEqualTo($record->expires_at)) {
            throw new GoneHttpException();
        }
        $this->assertReferences($user, (int) $record->organization_id,
            json_decode($record->source_refs, true, 512, JSON_THROW_ON_ERROR),
            json_decode($record->required_domains, true, 512, JSON_THROW_ON_ERROR));
        return $record;
    }

    public function isAssistantReportPath(string $path): bool
    {
        return (\Illuminate\Support\Facades\Schema::hasTable('ai_assistant_report_access') && DB::table('ai_assistant_report_access')->where('storage_path', $path)->exists())
            || \App\Models\PersonalFile::query()->where('storage_key', $path)->where('directory', 'reports/assistant')->exists();
    }

    public function urlForPath(string $path, ?User $actor): string
    {
        if ($actor === null) {
            throw new AccessDeniedHttpException();
        }
        $record = DB::table('ai_assistant_report_access')->where('storage_path', $path)->orderByDesc('created_at')->first();
        if ($record === null) {
            throw new AccessDeniedHttpException();
        }
        $token = rtrim(strtr(base64_encode(encrypt(['report_access_id' => $record->id])), '+/', '-_'), '=');
        $this->resolve($token, $actor);
        return '/api/v1/ai-assistant/reports/'.$token.'/download';
    }

    public function assertReferences(User $user, int $organizationId, array $refs, array $domains): void
    {
        if (! $this->policy->belongsToOrganization($user, $organizationId) || $refs === [] || $domains === []) {
            throw new AccessDeniedHttpException();
        }
        foreach (array_merge(['assistant', 'reports'], $domains) as $domain) {
            if (! is_string($domain) || ! $this->policy->canReadDomain($user, $organizationId, $domain)) {
                throw new AccessDeniedHttpException();
            }
        }
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                throw new AccessDeniedHttpException();
            }
            $allowed = $this->policy->canReadReference($user, $organizationId, $ref);
            if (! $allowed) {
                throw new AccessDeniedHttpException();
            }
        }
    }

    public function scopeDomainReferences(User $user, int $organizationId, array $domains, ?int $projectId): array
    {
        $types = ['projects' => 'project', 'contracts' => 'contract', 'finance' => 'payment_document', 'warehouse' => 'warehouse', 'schedule' => 'schedule', 'time_tracking' => 'time_entry'];
        $references = [];
        foreach ($domains as $domain) {
            $type = $types[$domain] ?? null;
            $query = $type === null ? null : $this->policy->entityQuery($user, $organizationId, $type);
            if ($query === null) { continue; }
            $table = $query->getModel()->getTable();
            if ($projectId !== null) {
                if ($type === 'project') { $query->whereKey($projectId); }
                elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'project_id')) { $query->where($table.'.project_id', $projectId); }
            }
            foreach ($query->pluck($table.'.id') as $id) { $references[] = ['entity_type' => $type, 'entity_id' => (string) $id]; }
        }
        return $references;
    }

    public function scopeReferences(User $user, int $organizationId, ?int $projectId, string $entityType = 'project'): array
    {
        $query = $this->policy->entityQuery($user, $organizationId, $entityType);
        if ($query === null) {
            throw new AccessDeniedHttpException();
        }
        if ($projectId !== null) {
            $query->whereKey($projectId);
        }
        return $query->pluck($query->getModel()->getTable().'.id')->map(static fn (mixed $id): array => ['entity_type' => $entityType, 'entity_id' => (string) $id])->all();
    }
}
