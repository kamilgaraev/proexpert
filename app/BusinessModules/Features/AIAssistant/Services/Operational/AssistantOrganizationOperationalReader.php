<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

abstract readonly class AssistantOrganizationOperationalReader
{
    public const TYPE = '';

    public function __construct(protected AssistantDataAccessPolicy $access) {}
    abstract protected function collect(User $actor, int $organizationId): array;
    abstract protected function format(array $data): string;

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        if ($arguments !== ['scope' => 'organization']) { throw new \InvalidArgumentException('invalid_organization_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')) { throw new AuthorizationException; }
            $data = ['as_of_date' => today()->toDateString()] + $this->collect($actor, $organizationId);
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'type' => static::TYPE, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'data' => $data]);
            $ref = ['entity_type' => static::TYPE, 'entity_id' => $organizationId, 'project_id' => null, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $data, 'server_formatted_answer' => $this->format($data)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== static::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['entity_id'] ?? null) !== $organizationId
            || ($ref['actor_id'] ?? null) !== (int) $actor->id || ! array_key_exists('project_id', $ref) || $ref['project_id'] !== null || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, ['scope' => 'organization'])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['scope' => 'organization']);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    protected static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
