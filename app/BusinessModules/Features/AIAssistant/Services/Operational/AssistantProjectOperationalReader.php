<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

abstract readonly class AssistantProjectOperationalReader
{
    public const TYPE = '';

    public function __construct(protected AssistantDataAccessPolicy $access) {}

    abstract protected function domain(): string;

    abstract protected function collect(User $actor, int $organizationId, int $projectId): array;

    abstract protected function format(array $data): string;

    protected function validArguments(array $arguments): bool
    {
        return is_int($arguments['project_id'] ?? null) && $arguments['project_id'] > 0 && array_keys($arguments) === ['project_id'];
    }

    protected function collectForArguments(User $actor, int $organizationId, array $arguments): array
    {
        return $this->collect($actor, $organizationId, $arguments['project_id']);
    }

    protected function readTime(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface(now());
    }

    protected function collectAt(User $actor, int $organizationId, array $arguments, \DateTimeImmutable $asOf): array
    {
        return $this->collectForArguments($actor, $organizationId, $arguments);
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || ! $this->validArguments($arguments)) {
            throw new \InvalidArgumentException('invalid_operational_scope');
        }

        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $arguments): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canReadDomain($actor, $organizationId, $this->domain())
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) {
                throw new AuthorizationException;
            }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->select(['id', 'name'])->first();
            if ($project === null) {
                throw new AuthorizationException;
            }
            $asOf = $this->readTime();
            $data = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => $asOf->format('Y-m-d')] + $this->collectAt($actor, $organizationId, $arguments, $asOf);
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'type' => static::TYPE, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'data' => $data]);
            $ref = ['entity_type' => static::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            if (array_keys($arguments) !== ['project_id']) {
                $ref['_read_arguments'] = $arguments;
            }

            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $data, 'server_formatted_answer' => $this->format($data)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== static::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ($ref['project_id'] ?? null) !== ($ref['entity_id'] ?? null) || ! is_int($ref['entity_id'] ?? null) || ! is_string($ref['source_version'] ?? null)) {
            return false;
        }
        $arguments = $ref['_read_arguments'] ?? ['project_id' => $ref['entity_id']];
        if (! is_array($arguments) || ($arguments['project_id'] ?? null) !== $ref['entity_id']) {
            return false;
        }
        try {
            return $this->read($actor, $organizationId, $arguments)['source_refs'][0]['source_version'] === $ref['source_version'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) {
            return null;
        }
        $current = $this->read($actor, $organizationId, $ref['_read_arguments'] ?? ['project_id' => $ref['entity_id']]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) {
            return null;
        }

        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    protected function projectQuery(User $actor, int $organizationId, int $projectId, string $type): ?Builder
    {
        $query = $this->access->entityQuery($actor, $organizationId, $type);
        if ($query !== null && ! $this->access->applyEntityProjectScope($actor, $organizationId, $type, $query, $projectId)) {
            return null;
        }

        return $query;
    }

    protected static function statusCounts(Builder $query): array
    {
        $table = $query->getModel()->getTable();

        return (clone $query)->reorder()->select($table.'.status')->selectRaw('COUNT(*) AS total')->groupBy($table.'.status')->orderBy($table.'.status')->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->getRawOriginal('status') => (int) $row->getAttribute('total')])->all();
    }

    protected function names(User $actor, int $organizationId, array $ids): array
    {
        return $this->access->entityQuery($actor, $organizationId, 'user')?->whereKey(array_values(array_unique(array_filter($ids))))->select(['id', 'name'])->get()
            ->mapWithKeys(static fn ($user): array => [(int) $user->id => (string) $user->name])->all() ?? [];
    }

    protected static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
