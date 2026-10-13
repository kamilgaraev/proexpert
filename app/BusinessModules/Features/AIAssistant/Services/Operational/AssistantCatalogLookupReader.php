<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTextSearch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantCatalogLookupReader
{
    public const TYPE = 'live_catalog_lookup_projection';

    public function __construct(private AssistantDataAccessPolicy $access) {}

    public static function hasIntent(string $query): bool
    {
        return preg_match('/справочник|каталог/iu', $query) === 1 && preg_match('/вид[а-яё]*\s+работ/iu', $query) === 1;
    }

    public static function arguments(string $query): ?array
    {
        if (! self::matches($query)) {
            return null;
        }
        if (preg_match('/(?:найди|покажи|поищи)\s+(?:в\s+(?:(?:нашем|мо[её]м|общем)\s+)?(?:справочник[а-яё]*|каталог[а-яё]*)\s+)?(?:материал[а-яё]*\s+)?(.{1,100}?)\s+и\s+вид[а-яё]*\s+работ\s+(?:по\s+)?(.{1,100}?)(?=[?.!;]|$)/iu', $query, $m) !== 1) {
            return null;
        }

        return ['material_query' => trim($m[1], " \t\n\r\0\x0B«»\""), 'work_type_query' => trim($m[2], " \t\n\r\0\x0B«»\"")];
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/создай|удали|измени|цен[а-яё]*|стоим|сумм|смет|остат|склад|поставк|сч[её]т|оплат|проект|объект|сотрудник|договор|уведомлен|расход|прибыл|затрат|бюджет|марж|рентабел|плат[её]ж|график|задач/iu', $query) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        if (count($arguments) !== 2 || array_diff(array_keys($arguments), ['material_query', 'work_type_query']) !== []) {
            throw new \InvalidArgumentException('invalid_catalog_query');
        }
        foreach ($arguments as $term) {
            if (! is_string($term) || trim($term) === '' || mb_strlen($term) > 100) {
                throw new \InvalidArgumentException('invalid_catalog_query');
            }
        }

        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $arguments): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')) {
                throw new AuthorizationException;
            }
            $data = [];
            $unitQuery = $this->access->entityQuery($actor, $organizationId, 'measurement_unit');
            foreach (['material', 'work_type'] as $type) {
                $query = $this->access->entityQuery($actor, $organizationId, $type);
                $term = $arguments[$type.'_query'];
                if ($query === null) {
                    $data[$type] = ['available' => false, 'query' => $term];

                    continue;
                }
                $searchTerm = preg_replace('/ирован(?:ие|ию|ия|ием|ии|ий)\b/iu', '', $term) ?? $term;
                $query->where(static function (\Illuminate\Database\Eloquent\Builder $search) use ($searchTerm): void {
                    $search->where(static function (\Illuminate\Database\Eloquent\Builder $exact) use ($searchTerm): void {
                        AssistantTextSearch::apply($exact, ['name', 'code'], $searchTerm, false);
                    });
                    if (preg_match('/^[а-яё]{5,40}$/iu', $searchTerm) === 1) {
                        $letters = mb_str_split(str_replace('ё', 'е', mb_strtolower($searchTerm)));
                        $variants = [];
                        for ($i = 1; $i < count($letters); $i++) {
                            $variants[] = implode('', array_slice($letters, 0, $i)).'[а-яе]?'.implode('', array_slice($letters, $i));
                        }
                        $search->orWhereRaw("replace(lower(name), 'ё', 'е') ~ ?", ['('.implode('|', $variants).')']);
                    }
                });
                $total = (clone $query)->count();
                $rows = $query->orderBy('name')->orderBy('id')->select(['id', 'name', 'code', 'measurement_unit_id'])->limit(20)->get();
                $units = $unitQuery === null ? collect() : (clone $unitQuery)->whereKey($rows->pluck('measurement_unit_id')->filter()->unique()->all())
                    ->select(['id', 'name', 'short_name'])->get()->keyBy('id');
                $data[$type] = ['available' => true, 'query' => $term, 'total' => $total, 'rows' => $rows->map(static function ($row) use ($units): array {
                    $unit = $units->get($row->measurement_unit_id);

                    return ['id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code, 'unit_id' => $row->measurement_unit_id,
                        'unit' => $unit ? trim($unit->name.' ('.$unit->short_name.')') : null];
                })->all()];
            }
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'type' => self::TYPE, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'arguments' => $arguments, 'data' => $data]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $organizationId, 'project_id' => null, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'arguments' => $arguments, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];

            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $data, 'server_formatted_answer' => $this->format($data)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['entity_id'] ?? null) !== $organizationId || ($ref['organization_id'] ?? null) !== $organizationId
            || ($ref['actor_id'] ?? null) !== (int) $actor->id || ! array_key_exists('project_id', $ref) || $ref['project_id'] !== null || ! is_array($ref['arguments'] ?? null) || ! is_string($ref['source_version'] ?? null)) {
            return false;
        }
        try {
            return $this->read($actor, $organizationId, $ref['arguments'])['source_refs'][0]['source_version'] === $ref['source_version'];
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
        $current = $this->read($actor, $organizationId, $ref['arguments']);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) {
            return null;
        }

        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private function format(array $data): string
    {
        $lines = [];
        foreach ($data as $type => $group) {
            $lines[] = trans_message('ai_assistant_operational.catalog_'.$type, ['query' => self::text($group['query'])]);
            if (! $group['available']) {
                $lines[] = trans_message('ai_assistant_operational.catalog_unavailable');

                continue;
            }
            $lines[] = trans_message('ai_assistant_operational.catalog_count', ['total' => $group['total']]);
            foreach ($group['rows'] as $row) {
                $lines[] = '- #'.$row['id'].' '.self::text($row['name']).'; '.trans_message('ai_assistant_operational.catalog_unit', ['unit' => $row['unit'] === null ? trans_message('ai_assistant_operational.catalog_unit_unknown') : self::text($row['unit'])]);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.catalog_basis');

        return implode("\n", $lines);
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
