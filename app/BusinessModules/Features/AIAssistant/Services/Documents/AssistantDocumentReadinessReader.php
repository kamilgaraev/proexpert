<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantDocumentReadinessReader
{
    public const TYPE = 'live_document_readiness_projection';
    public function __construct(private AssistantDataAccessPolicy $access, private AssistantDocumentCoverageService $coverage) {}

    public static function matches(string $query): bool
    {
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($query), $words);
        $allowed = explode(' ', 'какие какой сколько что как сейчас сегодня уже ещё еще пока не у нас наши наших все всех загруженные загруженных доступные доступных документы документов документам файлы файлов файлам готовы готов готова готово готовность готовности к поиску поиска статус статусы статусов обработки обрабатываются обрабатывается обрабатывают обработаны обработан обрабатывать очереди очередь ожидании ждут требуется распознавание распознавания повреждены поврежденные ошибки ошибка неподдерживаемые неподдерживаемых форматы формат с ними делать сделать дальше мне нам нужно надо действия действий покажи покажите перечисли список названия имена пожалуйста на в по нашем нашего текущем текущего выбранном выбранного этом этого проекте проекта проекту объекте объекта объекту и а помоги понять');
        if ($words[0] === [] || array_diff($words[0], $allowed) !== []) { return false; }
        return preg_match('/документ|файл/iu', $query) === 1
            && preg_match('/готов[а-яё]*\s+к\s+поиск|статус[а-яё]*\s+обработк|ещ[её]\s+обрабатыва|пока\s+не\s+готов/iu', $query) === 1
            && preg_match('/перескаж|пересказ|содержим|цитат|прочита|выдержк|описани|резюме|что\s+(?:там|в\s+них|написан)|текст|изложи|услови|требован|пункт|бюджет|расход|потрач|поставк|табел|[«»"]|по\s+(?!(?:статус|готов|обработк|наш|текущ|выбран|эт|проект|объект))/iu', $query) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (($projectId !== null && (! is_int($projectId) || $projectId < 1)) || array_keys($arguments) !== ['project_id']) { throw new \InvalidArgumentException('invalid_readiness_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $arguments): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || $projectId !== null && ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $query = $this->access->accessibleDocuments($actor, $organizationId);
            if ($projectId !== null) { $query->where('project_id', $projectId); }
            $counts = (clone $query)->reorder()->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
            $groups = []; $total = 0;
            foreach ($counts as $count) {
                $status = (string) $count->getRawOriginal('status');
                $number = (int) $count->getRawOriginal('total'); $total += $number;
                $examples = (clone $query)->where('status', $status)->reorder()->orderBy('id')->limit(3)
                    ->select(['id', 'filename', 'status', 'coverage_status', 'project_id', 'updated_at'])->get()->map(static fn ($document): array => $document->getAttributes())->all();
                $groups[] = ['status' => $status, 'total' => $number, 'examples' => $examples];
            }
            $fileCoverage = $this->coverage->coverage($organizationId, $actor, scope: ['project_id' => $projectId], countersOnly: true)['document_coverage'];
            $fileCoverage = array_intersect_key($fileCoverage, array_flip(['total', 'ready', 'pending', 'ocr_required', 'ocr_processing', 'failed', 'unsupported', 'empty', 'needs_access_review', 'storage_unverified']));
            $payload = ['project_id' => $projectId, 'total' => $total, 'groups' => $groups, 'file_coverage' => $fileCoverage];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 2, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId ?? $organizationId, 'project_id' => $projectId,
                'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'readiness_arguments' => $arguments,
                'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'success', 'read_scope' => 'document_metadata', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ! is_array($ref['readiness_arguments'] ?? null) || ($ref['readiness_arguments']['project_id'] ?? null) !== ($ref['project_id'] ?? null)
            || ($ref['entity_id'] ?? null) !== (($ref['project_id'] ?? null) ?? $organizationId) || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, $ref['readiness_arguments'])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, $ref['readiness_arguments']);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'verified', 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $data): string
    {
        $lines = [trans_message($data['project_id'] === null ? 'ai_assistant_readiness.scope_all' : 'ai_assistant_readiness.scope_project', ['project' => $data['project_id'], 'total' => $data['total']])];
        $coverage = $data['file_coverage'];
        $lines[] = trans_message('ai_assistant_readiness.file_counts', ['total' => $coverage['total'], 'ready' => $coverage['ready'], 'pending' => $coverage['pending'],
            'ocr' => $coverage['ocr_required'], 'ocr_processing' => $coverage['ocr_processing'], 'failed' => $coverage['failed'], 'unsupported' => $coverage['unsupported'],
            'empty' => $coverage['empty'], 'review' => $coverage['needs_access_review'] ?? 0, 'unverified' => $coverage['storage_unverified'] ?? 0]);
        $lines[] = trans_message('ai_assistant_readiness.registered_counts', ['total' => $data['total']]);
        foreach ($data['groups'] as $group) {
            $known = in_array($group['status'], ['queued', 'processing', 'ready', 'ocr_quote_required', 'ocr_approved', 'unsupported', 'damaged', 'failed'], true);
            $label = $known ? trans_message('ai_assistant_readiness.'.$group['status']) : trans_message('ai_assistant_readiness.unknown');
            $lines[] = "\n**".$label.'** — '.$group['total'];
            foreach ($group['examples'] as $example) {
                $name = htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $example['filename']) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lines[] = '- #'.$example['id'].' '.str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], $name);
            }
            $lines[] = trans_message('ai_assistant_readiness.window', ['shown' => count($group['examples']), 'total' => $group['total']]);
            $lines[] = trans_message('ai_assistant_readiness.'.($known ? $group['status'] : 'unknown').'_action');
        }
        $lines[] = trans_message('ai_assistant_readiness.basis');
        return implode("\n", $lines);
    }
}
